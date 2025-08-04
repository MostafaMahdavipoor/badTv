<?php

use LaraGram\Support\Facades\Bot;
use LaraGram\Request\Request;

Bot::onText('/start', function (Request $request) {
    $firstName = $request->message->from->first_name;
    $text = "سلام {$firstName}! ربات با موفقیت پاسخ داد.";
    $request->sendMessage(chat()->id, $text);
});
