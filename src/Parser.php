<?php

namespace Sepia\PoParser;

use Sepia\PoParser\Catalog\EntryFactory;
use Sepia\PoParser\Exception\ParseException;
use Sepia\PoParser\SourceHandler\FileSystem;
use Sepia\PoParser\SourceHandler\SourceHandler;
use Sepia\PoParser\SourceHandler\StringSource;

/**
 *    Copyright (c) 2012 Raúl Ferràs raul.ferras@gmail.com
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions
 *    are met:
 *    1. Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *    3. Neither the name of copyright holders nor the names of its
 *       contributors may be used to endorse or promote products derived
 *       from this software without specific prior written permission.
 *
 *    THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 *    ''AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED
 *    TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
 *    PURPOSE ARE DISCLAIMED.  IN NO EVENT SHALL COPYRIGHT HOLDERS OR CONTRIBUTORS
 *    BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 *    CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 * https://github.com/raulferras/PHP-po-parser
 *
 * Class to parse .po file and extract its strings.
 *
 * @version 5.0
 */
class Parser
{
    /** @var SourceHandler */
    protected $sourceHandler;

    /** @var int */
    protected $lineNumber;

    /** @var string */
    protected $property;

    /**
     * Reads and parses a string
     *
     * @param string $string po content
     *
     * @throws \Exception.
     * @return Parser
     */
    public static function parseString($string)
    {
        $parser = new Parser(new StringSource($string));
        $parser->parse();

        return $parser;
    }

    /**
     * Reads and parses a file
     *
     * @param string $filePath
     *
     * @throws \Exception.
     * @return Catalog
     */
    public static function parseFile($filePath)
    {
        $parser = new Parser(new FileSystem($filePath));

        return $parser->parse();
    }

    public function __construct(SourceHandler $sourceHandler)
    {
        $this->sourceHandler = $sourceHandler;
    }

    /**
     * Reads and parses strings of a .po file.
     *
     * @param SourceHandler . Optional
     *
     * @throws \Exception, \InvalidArgumentException, ParseException
     * @return Catalog
     */
    public function parse()
    {
        $catalog = new Catalog();
        $this->lineNumber = 0;
        $entry = array();
        $this->mode = null;     // current mode
        $this->property = null; // current property

        // Flags
        $headersFound = false;

        // A new entry has been just inserted.

        // Used to remember last key in a multiline previous entry.
        $lastPreviousKey = null;
        $state = null;

        while (!$this->sourceHandler->ended()) {

            $line = trim($this->sourceHandler->getNextLine());

            if ($this->shouldIgnoreLine($line, $entry)) {
                $this->lineNumber++;
                continue;
            }

            if ($this->shouldCloseEntry($line, $entry)) {
                if (!$headersFound && $this->isHeader($entry)) {
                    $headersFound = true;
                    $catalog->addHeaders(array_filter(explode('\\n', $entry['msgstr'])));
                } else {
                    $catalog->addEntry(EntryFactory::createFromArray($entry));
                }

                $entry = array();
                $this->mode = null;
                $this->property = null;

                if (empty($line)) {
                    $this->lineNumber++;
                    continue;
                }
            }

            $firstChar = strlen($line) > 0 ? $line[0] : '';

            switch ($firstChar) {
                case '#':
                    $entry = $this->parseComment($line, $entry);
                    break;

                case 'm':
                    $entry = $this->parseProperty($line, $entry);
                    break;

                case '"':
                    $entry = $this->parseMultiline($line, $entry);
                    break;
            }

            $this->lineNumber++;
            continue;


            $split = preg_split('/\s+/ ', $line, 2);
            $lineKey = $split[0];

            if (empty($line) && count($entry) === 0) {
                $lineNumber++;
                continue;
            }


            // If a blank line is found, or a new msgid when already got one
            if ($line === '' || ($lineKey === 'msgid' && isset($entry['msgid']))) {
                // Two consecutive blank lines
                if ($justNewEntry) {
                    $lineNumber++;
                    continue;
                }

                if ($firstLine) {
                    $firstLine = false;
                    if (self::isHeader($entry)) {
                        $catalog->addHeaders(array_filter(explode('\\n', $entry['msgstr'])));
                    } else {
                        $catalog->addEntry(EntryFactory::createFromArray($entry));
                    }
                } else {
                    // A new entry is found!
                    $catalog->addEntry(EntryFactory::createFromArray($entry));
                }

                $entry = array();
                $state = null;
                $justNewEntry = true;
                $lastPreviousKey = null;
                if ($line === '') {
                    $lineNumber++;
                    continue;
                }
            }

            $justNewEntry = false; // ?
            $data = isset($split[1]) ? $split[1] : null;

            switch ($lineKey) {
                // Flagged translation
                case '#,':
                    $entry['flags'] = preg_split('/,\s*/', $data);
                    break;

                // # Translator comments
                case '#':
                    $entry['tcomment'] = !isset($entry['tcomment']) ? array() : $entry['tcomment'];
                    $entry['tcomment'][] = $data;
                    break;

                // #. Comments extracted from source code
                case '#.':
                    $entry['ccomment'] = !isset($entry['ccomment']) ? array() : $entry['ccomment'];
                    $entry['ccomment'][] = $data;
                    break;

                // Reference
                case '#:':
                    $entry['reference'][] = addslashes($data);
                    break;


                case '#|':      // #| Previous untranslated string
                case '#~':      // #~ Old entry
                case '#~|':     // #~| Previous-Old untranslated string. Reported by @Cellard
                    switch ($lineKey) {
                        case '#|':
                            $lineKey = 'previous';
                            break;
                        case '#~':
                            $lineKey = 'obsolete';
                            break;
                        case '#~|':
                            $lineKey = 'previous-obsolete';
                            break;
                    }

                    $tmpParts = explode(' ', $data);
                    $tmpKey = $tmpParts[0];

                    if (!in_array($tmpKey, array('msgid', 'msgid_plural', 'msgstr', 'msgctxt'), true)) {
                        // If there is a multi-line previous string we must remember
                        // what key was first line.
                        $tmpKey = $lastPreviousKey;
                        $str = $data;
                    } else {
                        $str = implode(' ', array_slice($tmpParts, 1));
                    }

                    //array('obsolete' => true, 'msgid' => '', 'msgstr' => '');

                    if (strpos($lineKey, 'obsolete') !== false) {
                        $entry['obsolete'] = true;
                        switch ($tmpKey) {
                            case 'msgid':
                                if (!isset($entry['msgid'])) {
                                    $entry['msgid'] = '';
                                }
                                $entry['msgid'] .= trim($str, '"');
                                $lastPreviousKey = $tmpKey;
                                break;

                            case 'msgstr':
                                if (!isset($entry['msgstr'])) {
                                    $entry['msgstr'] = '';
                                }
                                $entry['msgstr'] .= trim($str, '"');
                                $lastPreviousKey = $tmpKey;
                                break;

                            case 'msgctxt':
                                $entry['msgctxt'] = trim($str, '"');
                                $lastPreviousKey = $tmpKey;
                                break;

                            default:
                                break;
                        }
                    } else {
                        $entry[$lineKey] = isset($entry[$lineKey]) ? $entry[$lineKey] : array(
                            'msgid' => '',
                            'msgstr' => '',
                        );
                    }

                    if ($lineKey !== 'obsolete') {
                        switch ($tmpKey) {
                            case 'msgid':
                            case 'msgid_plural':
                            case 'msgstr':
                                if (!isset($entry[$lineKey][$tmpKey])) {
                                    $entry[$lineKey][$tmpKey] = '';
                                }
                                $entry[$lineKey][$tmpKey] .= trim($str, '"');
                                $lastPreviousKey = $tmpKey;
                                break;

                            default:
                                $entry[$lineKey][$tmpKey] = $str;
                                break;
                        }
                    }
                    break;


                // context
                // Allows disambiguations of different messages that have same msgid.
                // Example:
                //
                // #: tools/observinglist.cpp:700
                // msgctxt "First letter in 'Scope'"
                // msgid "S"
                // msgstr ""
                //
                // #: skycomponents/horizoncomponent.cpp:429
                // msgctxt "South"
                // msgid "S"
                // msgstr ""
                case 'msgctxt':
                    // untranslated-string
                case 'msgid':
                    // untranslated-string-plural
                case 'msgid_plural':
                    $state = $lineKey;
                    if (!isset($entry[$state])) {
                        $entry[$state] = '';
                    }

                    $entry[$state] .= trim($data, '"');
                    break;
                // translated-string
                case 'msgstr':
                    $state = 'msgstr';
                    $entry[$state] = trim($data, '"');
                    break;

                default:
                    if (strpos($lineKey, 'msgstr[') !== false) {
                        // translated-string-case-n
                        $state = $lineKey;
                        $entry[$state] = trim($data, '"');
                    } else {
                        // "multiline" lines
                        switch ($state) {
                            case 'msgctxt':
                            case 'msgid':
                            case 'msgid_plural':
                            case (strpos($state, 'msgstr[') !== false):
                                if (!isset($entry[$state])) {
                                    $entry[$state] = '';
                                }

                                if (is_string($entry[$state])) {
                                    // Convert it to array
                                    //$entry[$state] = array($entry[$state]);
                                    $entry[$state] = trim($entry[$state], '"');
                                }
                                $entry[$state] .= trim($line, '"');
                                break;

                            case 'msgstr':
                                // Special fix where msgid is ""
                                $entry['msgstr'] .= trim($line, '"');
                                /*if ($entry['msgid'] === "\"\"") {
                                    $entry['msgstr'].= trim($line, '"');
                                } else {
                                    $entry['msgstr'].= $line;
                                }*/
                                break;

                            default:
                                throw new \Exception(
                                    'Parser: Parse error! Unknown key "'.$lineKey.'" on line '.($lineNumber + 1)
                                );
                        }
                    }
                    break;
            }

            $lineNumber++;
        }
        $this->sourceHandler->close();

        // add final entry
        if (count($entry)) {
            $catalog->addEntry(EntryFactory::createFromArray($entry));
        }

        return $catalog;
    }

    protected function shouldIgnoreLine($line, array $entry)
    {
        return empty($line) && count($entry) === 0;
    }


    protected function shouldCloseEntry($line, array $entry)
    {
        $lineKey = '';

        return ($line === '' || ($lineKey === 'msgid' && isset($entry['msgid'])));
    }

    /**
     * Checks if entry is a header by
     *
     * @param array $entry
     *
     * @return bool
     */
    protected function isHeader(array $entry)
    {
        if (empty($entry) || !isset($entry['msgstr'])) {
            return false;
        }

        if (!isset($entry['msgid']) || !empty($entry['msgid'])) {
            return false;
        }

        $headerKeys = array(
            'Project-Id-Version:' => false,
            'Report-Msgid-Bugs-To:' => false,
            'POT-Creation-Date:' => false,
            'PO-Revision-Date:' => false,
            'Last-Translator:' => false,
            'Language-Team:' => false,
            'MIME-Version:' => false,
            'Content-Type:' => false,
            'Content-Transfer-Encoding:' => false,
            'Plural-Forms:' => false,
        );
        $count = count($headerKeys);
        $keys = array_keys($headerKeys);

        $headerItems = 0;
        $lines = explode("\\n", $entry['msgstr']);

        foreach ($lines as $str) {
            $tokens = explode(':', $str);
            $tokens[0] = trim($tokens[0], '"').':';

            if (in_array($tokens[0], $keys, true)) {
                $headerItems++;
                unset($headerKeys[$tokens[0]]);
                $keys = array_keys($headerKeys);
            }
        }

        return $headerItems === $count;
    }

    /**
     * @param string $line
     *
     * @return array
     */
    protected function getProperty($line)
    {
        $tokens = preg_split('/\s+/ ', $line, 2);

        return $tokens;
    }

    /**
     * @param string $line
     * @param array  $entry
     *
     * @return array
     * @throws ParseException
     */
    private function parseProperty($line, array $entry)
    {
        list($key, $value) = $this->getProperty($line);

        if (!isset($entry[$key])) {
            $entry[$key] = '';
        }

        switch (true) {
            case $key === 'msgctxt':
            case $key === 'msgid':
            case $key === 'msgid_plural':
            case $key === 'msgstr':
                $entry[$key].= trim($value, '"');
                $this->property = $key;
                break;

            case strpos($key, 'msgstr[') !== false:
                $entry[$key].= trim($value, '"');
                break;

            default:
                throw new ParseException(sprintf('Could not parse %s at line %d', $key, $this->lineNumber));
        }

        return $entry;
    }

    /**
     * @param string $line
     * @param array  $entry
     *
     * @return array
     * @throws ParseException
     */
    private function parseMultiline($line, $entry)
    {
        switch ($this->property) {
            case 'msgctxt':
            case 'msgid':
            case 'msgid_plural':
            case 'msgstr':
                $entry[$this->property].= trim($line, '"');
                break;

            default:
                throw new ParseException(
                    sprintf('Error parsing property %s as multiline.', $this->property)
                );
        }

        return $entry;
    }

    /**
     * @param string $line
     * @param array  $entry
     *
     * @return array
     */
    private function parseComment($line, $entry)
    {
        return $entry;
    }
}