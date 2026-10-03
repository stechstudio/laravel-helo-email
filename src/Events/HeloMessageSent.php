<?php

namespace STS\HeloEmail\Events;

use Illuminate\Mail\SentMessage;
use STS\HeloEmail\HeloResult;

/** Fired after Laravel sends a message through the Helo API. */
class HeloMessageSent
{
    public function __construct(public SentMessage $sent, public HeloResult $result)
    {
    }
}
