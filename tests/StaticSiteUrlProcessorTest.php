<?php

namespace PhpTek\Exodus\Test;

use SilverStripe\Dev\SapphireTest;

/**
 *
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 */
class StaticSiteUrlProcessorTest extends SapphireTest
{
    /**
     * Tests StaticSiteURLProcessorDropExtensions URL Processor
     */
    public function testStaticSiteURLProcessorDropExtensions()
    {
        $processor = new StaticSiteURLProcessorDropExtensions();

        $this->assertFalse($processor->processUrl('http://test.com/test1.html'));
        $this->assertFalse($processor->processUrl(''));
        $this->assertFalse($processor->processUrl(array()));

        $testUrl4CharSufx = $processor->processUrl(array(
            'url' => 'http://fluff.com/test1.html',
            'mime' => 'text/html'
        ));
        $this->assertEquals('http://fluff.com/test1', $testUrl4CharSufx['url']);

        $testUrl3CharSufx = $processor->processUrl(array(
            'url' => 'http://fluff.com/test2.htm',
            'mime' => 'text/html'
        ));
        $this->assertEquals('http://fluff.com/test2', $testUrl3CharSufx['url']);

        $testUrlNoCharSufx = $processor->processUrl(array(
            'url' => 'http://fluff.com/test3',
            'mime' => 'text/html'
        ));
        $this->assertEquals('http://fluff.com/test3', $testUrlNoCharSufx['url']);

        $testUrlWithBrackets = $processor->processUrl(array(
            'url' => 'http://fluff.com/test3/(subdir)',
            'mime' => 'text/html'
        ));
        $this->assertEquals('http://fluff.com/test3/subdir', $testUrlWithBrackets['url']);
    }

    /**
     * Tests StaticSiteURLProcessorDropExtensions URL Processor
     */
    public function testStaticSiteMOSSURLProcessor()
    {
        $processor = new StaticSiteMOSSURLProcessor();

        $this->assertFalse($processor->processUrl('http://test.com/test1.html'));
        $this->assertFalse($processor->processUrl(''));
        $this->assertFalse($processor->processUrl(array()));

        $testUrlWithPages = $processor->processUrl(array(
            'url' => 'http://fluff.com/Pages/test1.aspx',
            'mime' => 'text/html'
        ));
        $this->assertEquals('http://fluff.com/test1', $testUrlWithPages['url']);
    }
}
