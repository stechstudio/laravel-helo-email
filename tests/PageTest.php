<?php

namespace STS\HeloEmail\Tests;

use Closure;
use STS\HeloEmail\HeloException;
use STS\HeloEmail\Page;
use STS\HeloEmail\Response;

class PageTest extends TestCase
{
    /** @param array<int, array<mixed>> $pages keyed by offset */
    protected function offsets(array $pages, array &$calls = []): Closure
    {
        return function (int $offset) use ($pages, &$calls) {
            $calls[] = $offset;

            return $pages[$offset];
        };
    }

    public function testOffsetPagingWalksEveryPageLazily(): void
    {
        $calls = [];
        $page = Page::offset($this->offsets([
            0 => ['totalCount' => 3, 'results' => [['email' => 'a@example.com'], ['email' => 'b@example.com']]],
            2 => ['totalCount' => 3, 'results' => [['email' => 'c@example.com']]],
        ], $calls));

        $this->assertSame([0], $calls, 'only the first page is fetched up front');
        $this->assertCount(2, $page);
        $this->assertSame(3, $page->total());
        $this->assertTrue($page->hasMore());
        $this->assertInstanceOf(Response::class, $page->items()->first());

        $emails = $page->lazy()->map(fn (Response $row) => $row->email)->all();

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $emails);
        $this->assertSame([0, 2], $calls);
    }

    public function testOffsetPagingStartsAtTheGivenOffset(): void
    {
        $calls = [];
        Page::offset($this->offsets([5 => ['totalCount' => 6, 'results' => [['id' => 'x']]]], $calls), 5);

        $this->assertSame([5], $calls);
    }

    public function testAnEmptyListHasNoMorePages(): void
    {
        $page = Page::offset($this->offsets([0 => ['totalCount' => 0, 'results' => []]]));

        $this->assertFalse($page->hasMore());
        $this->assertNull($page->next());
        $this->assertSame([], $page->lazy()->all());
    }

    public function testOffsetPagingRejectsAnEmptyPageBeforeTheTotal(): void
    {
        // A short list must never pass for a complete one.
        $page = Page::offset($this->offsets([
            0 => ['totalCount' => 4, 'results' => [['id' => 1], ['id' => 2]]],
            2 => ['totalCount' => 4, 'results' => []],
        ]));

        $this->expectException(HeloException::class);
        $this->expectExceptionMessage('Helo returned an empty page at offset 2 of 4.');

        $page->lazy()->all();
    }

    public function testOffsetPagingRequiresAValidTotalOnTheFirstPage(): void
    {
        // Postmaster clears local suppressions a "complete" list doesn't
        // contain, so a list without a usable total must never look complete.
        foreach ([['results' => []], ['totalCount' => null, 'results' => []], ['totalCount' => -1, 'results' => []], ['totalCount' => 1.5, 'results' => []], ['totalCount' => 'bad', 'results' => []]] as $json) {
            try {
                Page::offset(fn () => $json);
                $this->fail('Expected a HeloException for '.json_encode($json));
            } catch (HeloException $exception) {
                $this->assertSame('Helo returned a list response without a valid totalCount.', $exception->getMessage());
            }
        }
    }

    public function testOffsetPagingRequiresAValidTotalOnLaterPages(): void
    {
        foreach ([['results' => [['id' => 2]]], ['totalCount' => -1, 'results' => [['id' => 2]]], ['totalCount' => '2', 'results' => [['id' => 2]]]] as $later) {
            $page = Page::offset($this->offsets([0 => ['totalCount' => 2, 'results' => [['id' => 1]]], 1 => $later]));

            try {
                $page->lazy()->all();
                $this->fail('Expected a HeloException for '.json_encode($later));
            } catch (HeloException $exception) {
                $this->assertSame('Helo returned a list response without a valid totalCount.', $exception->getMessage());
            }
        }
    }

    public function testRejectsAListResponseWithoutResults(): void
    {
        foreach ([['totalCount' => 1], ['totalCount' => 1, 'results' => 'nope'], ['totalCount' => 1, 'results' => ['not-an-object']]] as $json) {
            try {
                Page::offset(fn () => $json);
                $this->fail('Expected a HeloException for '.json_encode($json));
            } catch (HeloException $exception) {
                $this->assertStringStartsWith('Helo returned', $exception->getMessage());
            }
        }
    }

    public function testCursorPagingFollowsAfterUntilAnEmptyPage(): void
    {
        $calls = [];
        $page = Page::cursor(function (?int $after) use (&$calls) {
            $calls[] = $after;

            return match ($after) {
                null => ['after' => 10, 'totalCount' => 3.0, 'results' => [['id' => 'a'], ['id' => 'b']]],
                10 => ['after' => 11, 'totalCount' => 3.0, 'results' => [['id' => 'c']]],
                11 => ['totalCount' => 3.0, 'results' => []],
            };
        });

        $this->assertSame(3, $page->total());
        $this->assertSame(['a', 'b', 'c'], $page->lazy()->map(fn ($row) => $row->id)->all());
        $this->assertSame([null, 10, 11], $calls);
    }

    public function testCursorPagingStopsWhenTheCursorDoesNotMove(): void
    {
        $calls = 0;
        $page = Page::cursor(function (?int $after) use (&$calls) {
            $calls++;

            return ['after' => 7, 'results' => [['id' => $calls]]];
        }, 7);

        $this->assertFalse($page->hasMore());
        $this->assertSame([1], $page->lazy()->map(fn ($row) => $row->id)->all());
        $this->assertSame(1, $calls);
    }

    public function testCursorPagingStopsWithoutACursor(): void
    {
        $page = Page::cursor(fn () => ['results' => [['id' => 'only']]]);

        $this->assertFalse($page->hasMore());
        $this->assertNull($page->total());
    }

    public function testResponsesReadAsPropertiesOrArrayKeys(): void
    {
        $response = new Response(['status' => 'verified', 'records' => [['type' => 'txt']]]);

        $this->assertSame('verified', $response->status);
        $this->assertSame('verified', $response['status']);
        $this->assertSame('txt', $response->records[0]['type']);
        $this->assertNull($response->missing);
    }
}
