<?php

namespace Tests\Unit;

use App\Telegram\TelegramMessage;
use PHPUnit\Framework\TestCase;

class TelegramMessageTest extends TestCase
{
    public function test_escape_neutralises_html(): void
    {
        $this->assertSame('&lt;b&gt; &amp; &quot;x&quot;', TelegramMessage::escape('<b> & "x"'));
        $this->assertSame('', TelegramMessage::escape(null));
    }

    public function test_markdown_turns_only_bold_into_tags_after_escaping(): void
    {
        $this->assertSame('<b>Status:</b> a &lt;i&gt;b&lt;/i&gt;', TelegramMessage::markdown('**Status:** a <i>b</i>'));
        $this->assertSame('<b>&lt;script&gt;</b>', TelegramMessage::markdown('**<script>**'));
        $this->assertSame('*one* _two_', TelegramMessage::markdown('*one* _two_'));
    }
}
