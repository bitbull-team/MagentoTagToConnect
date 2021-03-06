<?php
/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2014 Daniel Deady
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

class Clockworkgeek_TagToConnect_Package extends Mage_Connect_Package
{

    /**
     * @var Clockworkgeek_TagToConnect_GitTag
     */
    protected $tag;

    /**
     * New package based on an annotated tag
     * 
     * @param Clockworkgeek_TagToConnect_GitTag $tag
     * @param string $source Filename or XML
     */
    public function __construct(Clockworkgeek_TagToConnect_GitTag $tag, $source = null)
    {
        parent::__construct($source);
        // a safe bet for Connect 2.0
        $this->getChannel() || $this->setChannel('community');
        $this->getDependencyPhpVersionMin() || $this->setDependencyPhpVersion('5.2.0', '6.0.0');

        $this->tag = $tag;
    }

    /**
     * Update this package with details gleaned from Composer
     * 
     * @param string $filename
     * @return Clockworkgeek_TagToConnect_Package
     */
    public function loadComposerJson($filename = 'composer.json')
    {
        if ($this->tag->getFileExists($filename)) {
            $contents = implode("\n", $this->tag->getFileContents($filename));
            $json = json_decode($contents);
            if (json_last_error() !== JSON_ERROR_NONE) {
                trigger_error(json_last_error_msg(), E_USER_ERROR);
            }

            // license URLs are automated
            if (($license = @$json->license)) {
                $this->setLicense($license, self::lookupLicenseUrl($license));
            }

            // use description as summary because it is typically short
            if (($description = @$json->description)) {
                $this->setSummary($description);
            }

            // might have to guess at author's username
            foreach ((array)@$json->authors as $author) { 
                if (! @$author->name || ! @$author->email) {
                    continue;
                }
                if (! isset($author->user)) {
                    sscanf($author->email, '%[^@]', $author->user);
                    trigger_error("Assumed username '{$author->user}' from email '{$author->email}'", E_USER_NOTICE);
                }
                $this->addAuthor($author->name, $author->user, $author->email);
            }

            $requires = (array) @$json->require;
            if ($requires) {
                $this->addConnectDependencies($requires);
            }
        }
        else {
            trigger_error("There is no 'composer.json' in '{$this->tag->getName()}'", E_USER_WARNING);
        }
        return $this;
    }

    public function addConnectDependencies(array $requires)
    {
        // array keys are preserved throughout
        $requires = array_map('Clockworkgeek_TagToConnect_Package::parseVersions', $requires);
        // filter out requirements which could not be parsed
        $requires = array_filter($requires);
        $names = array_keys($requires);
        $tokens = preg_filter(
            array('#^connect20/([^_/]+_[^_/]+)$#', '#^([^/]+)/([^/]+)$#'),
            array('$1', '$1_$2'),
            // keys are preserved so must be the original name
            // values are lower case for insensitive comparison later
            array_combine(
                $names,
                array_map('strtolower', $names)
            )
        );
        if ($tokens) {
            // magento packages typically only have 'word' characters
            $tokens = preg_replace('#[^\w/]+#', '', $tokens);

            // allow local composer take precedence
            // else assume it's installed globally
            // assumption is valid because user must have composer somewhere
            $command = file_exists('composer.phar') ? 'php -f composer.phar --' : 'composer';
            $command .= ' search ';
            $command .= implode(' ', $tokens);

            // perform search on all relevant repositories
            $results = array();
            trigger_error('Searching repository for dependencies...', E_USER_NOTICE);
            exec($command, $results);
            $results = preg_filter('#^connect20/(\w+).*#', '$1', $results);
            // case insensitive compare
            $results = array_intersect_key(
                $results,
                array_unique(array_map('strtolower', $results))
            );

            foreach ($results as $name) {
                trigger_error("Assumed package '{$name}' is required", E_USER_NOTICE);
                // search for adjusted value, return key which is original name
                $require = array_search(strtolower($name), $tokens);
                $min = $max = null;
                if ($require && isset($requires[$require])) {
                    list($min, $max) = $requires[$require];
                }
                // else search didn't find version numbers, proceed anyway
                $this->addDependencyPackage($name, 'community', $min, $max);
            }
        }
    }

    /**
     * Returns minimum and maximum as tuple
     * 
     * @param string $data
     * @return array
     */
    public static function parseVersions($input)
    {
        switch (true) {
            case preg_match('/^>=([-\w\.]+)$/', $input, $vers):
                return array($vers[1], null);
            case preg_match('/^>=([-\w\.]+) <([-\w\.]+)$/', $input, $vers):
                return array($vers[1], $vers[2]);
            case preg_match('/^[~^](\d+)\.(\d+)$/', $input, $vers):
                return array(
                    $vers[1].'.'.$vers[2],
                    ($vers[1]+1).'.0'
                );
            case preg_match('/^~(\d+)\.(\d+)\.([\d\.]+)/', $input, $vers):
                return array(
                    $vers[1].'.'.$vers[2].'.'.$vers[3],
                    $vers[1].'.'.($vers[2]+1).'.0'
                );
            case preg_match('/^\^(\d+)\.(\d+)\.([\d\.]+)/', $input, $vers):
                return array(
                    $vers[1].'.'.$vers[2].'.'.$vers[3],
                    ($vers[1]+1).'.0.0'
                );
            default:
                array(null, null);
        }
    }

    public static function lookupLicenseUrl($code)
    {
        $repository = new \LicenseData\Repository();
        $license = $repository->get($code);
        return $license ? $license->getUrl() : null;
    }

    /**
     * Read modman from tag, if it exists
     * 
     * @todo Read extra.map from composer like https://github.com/magento-hackathon/magento-composer-installer/blob/master/doc/Mapping.md
     * @return array Destination paths keyed by origins regex
     */
    public function getMap()
    {
        $modman = $this->tag->getFileContents('modman');
        if ($modman === false) return array();

        $map = array();
        foreach ($modman as $line) {
            if (($line[0] === '#') || ($line[0] === '@')) continue;

            // CSV func handles quotes and escaped chars nicely
            $row = str_getcsv($line, ' ');
            $row = array_filter($row);
            if (count($row) === 2) {
                list($orig, $dest) = array_values($row);
                $orig = '#^'.str_replace('*', '[^\\/]*', $orig).'#';
                $map[$orig] = $dest;
            }
        }
        return $map;
    }

    /**
     * Update this package with tag contents
     * 
     * Package name must be set before calling loadGitTag()
     * 
     * @return Clockworkgeek_TagToConnect_Package
     */
    public function loadGitTag()
    {
        list($version, $stability) = $this->tag->getVersion();
        $this->setVersion($version);
        $this->setStability($stability);

        sscanf($this->tag->getDatetime(), '%s %s', $date, $time);
        $this->setDate($date);
        $this->setTime($time);

        $this->setNotes($this->tag->getMessage());

        // dest = var/package/tmp/Module_Name-X.X.X/
        $dest = Mage_Connect_Package_Writer::PATH_TO_TEMPORARY_DIRECTORY . basename($this->getReleaseFilename()) . DS;
        $filenames = $this->tag->getFilenames();
        $projectdir = getcwd();
        @mkdir($dest, 0700, true);
        chdir($dest);

        $map = $this->getMap();
        foreach ($filenames as $filename) {
            if ($map) {
                $mappedfile = preg_replace(array_keys($map), $map, $filename, 1, $replaced);
                if (! $replaced) continue;
            }
            else {
                $mappedfile = $filename;
            }
            @mkdir(dirname($mappedfile), 0700, true);
            $this->tag->saveFileContents($filename, $mappedfile);

            list($target, $mappedfile) = $this->getTargetName($mappedfile);
            $this->addContent($mappedfile, $target);
        }

        chdir($projectdir);

        return $this;
    }

    protected function getTargetName($uri)
    {
        // it's important to canonicalise
        $uri = realpath($uri);
        if (!$uri) {
            return null;
        }

        foreach ($this->getTarget()->getTargets() as $name => $targetUri) {
            // realpath checks if file exists, which is useful
            if (($targetUri = realpath($targetUri))) {
                $targetUri .= DS;
                if (strpos($uri, $targetUri) === 0) {
                    return array($name, substr($uri, strlen($targetUri)));
                }
            }
        }
        // else not in our dir at all

        // this method can never find targets "magetest" nor "mage"
        return null;
    }

    protected function _savePackage($path)
    {
        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }
        return parent::_savePackage($path);
    }
}
