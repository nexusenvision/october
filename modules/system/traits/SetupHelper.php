<?php namespace System\Traits;

use App;
use Str;
use Lang;
use Config;
use Exception;
use System\Classes\UpdateManager;
use Illuminate\Support\Env;
use Dotenv\Dotenv;
use PDOException;
use PDO;

/**
 * SetupHelper is shared logic for the handlers
 */
trait SetupHelper
{
    /**
     * @var array userConfig is a temporary store of user input config values
     */
    protected $userConfig = [];

    /**
     * setComposerAuth configures authentication for composer and October CMS
     */
    protected function setComposerAuth($email, $projectKey)
    {
        $composerUrl = $this->getComposerUrl(false);

        $this->injectJsonToFile(base_path('auth.json'), [
            'http-basic' => [
                $composerUrl => [
                    'username' => $email,
                    'password' => $projectKey
                ]
            ]
        ]);

        $this->injectJsonToFile(storage_path('cms/project.json'), [
            'project' => $projectKey
        ]);
    }

    /**
     * setDemoContent instructs the system to install demo content or not
     */
    protected function setDemoContent($confirm = true)
    {
        if ($confirm) {
            $this->injectJsonToFile(storage_path('cms/autoexec.json'), [
                'theme:seed demo --root'
            ]);
        }
        else {
            $this->injectJsonToFile(storage_path('cms/autoexec.json'), [
                'october:fresh --force'
            ]);
        }
    }

    /**
     * composerRequireString returns the composer require string for installing dependencies
     */
    protected function composerRequireCore($composer, $want = null)
    {
        if ($want === null) {
            $composer->require('october/all', UpdateManager::WANT_VERSION);
        }
        else {
            $want = $this->processWantString($want);
            $composer->require('october/rain', $want);
            $composer->require('october/all', $want);
        }
    }

    /**
     * processWantString ensures a valid want version is supplied
     */
    protected function processWantString($version)
    {
        $parts = explode('.', $version);

        if (count($parts) > 1) {
            $parts[2] = '*';
        }

        $parts = array_slice($parts, 0, 3);

        return implode('.', $parts);
    }

    /**
     * checkEnvWritable checks to see if the app can write to the .env file
     */
    protected function checkEnvWritable()
    {
        $path = base_path('.env');
        $gitignore = base_path('.gitignore');

        // Copy environment variables and reload
        if (!file_exists($path)) {
            copy(base_path('.env.example'), $path);
            $this->refreshEnvVars();
        }

        // Add modules to .gitignore
        if (file_exists($gitignore) && is_writable($gitignore)) {
            $this->addModulesToGitignore($gitignore);
        }

        return is_writable($path);
    }

    /**
     * addModulesToGitignore
     */
    protected function addModulesToGitignore($gitignore)
    {
        $toIgnore = '/modules';
        $contents = file_get_contents($gitignore);

        if (strpos($contents, $toIgnore) === false) {
            file_put_contents($gitignore,
                trim(file_get_contents($gitignore), PHP_EOL) . PHP_EOL .
                $toIgnore . PHP_EOL
            );
        }
    }

    /**
     * refreshEnvVars will reload defined environment variables
     */
    protected function refreshEnvVars()
    {
        DotEnv::create(Env::getRepository(), App::environmentPath(), App::environmentFile())->load();
    }

    /**
     * setEnvVars sets multiple environment variables
     */
    protected function setEnvVars(array $vars)
    {
        foreach ($vars as $key => $val) {
            $this->setEnvVar($key, $val);
        }
    }

    /**
     * setEnvVar writes an environment variable to disk
     */
    protected function setEnvVar($key, $value)
    {
        $path = base_path('.env');
        $old = $this->getEnvVar($key);

        if (is_bool(env($key))) {
            $old = env($key) ? 'true' : 'false';
        }

        if (file_exists($path)) {
            file_put_contents($path, str_replace(
                "$key=".$old,
                "$key=".$value,
                file_get_contents($path)
            ));
        }

        $this->userConfig[$key] = $value;
    }

    /**
     * getEnvVar specifically from installer specified values. This is needed since
     * the writing to the environment file may not update the values from env()
     */
    protected function getEnvVar(string $key, $default = null)
    {
        return $this->userConfig[$key] ?? env($key, $default);
    }

    /**
     * checkDatabase validates the supplied database configuration
     */
    protected function checkDatabase($type, $host, $port, $name, $user, $pass)
    {
        if ($type != 'sqlite' && !strlen($host)) {
            throw new Exception('Please specify a database host');
        }

        if (!strlen($name)) {
            throw new Exception('Please specify the database name');
        }

        // Check connection
        switch ($type) {
            case 'mysql':
                $dsn = 'mysql:host='.$host.';dbname='.$name;
                if ($port) $dsn .= ";port=".$port;
                break;

            case 'pgsql':
                $_host = ($host) ? 'host='.$host.';' : '';
                $dsn = 'pgsql:'.$_host.'dbname='.$name;
                if ($port) $dsn .= ";port=".$port;
                break;

            case 'sqlite':
                $dsn = 'sqlite:'.$name;
                $this->checkSqliteFile($name);
                break;

            case 'sqlsrv':
                $availableDrivers = PDO::getAvailableDrivers();
                $_port = $port ? ','.$port : '';
                if (in_array('dblib', $availableDrivers)) {
                    $dsn = 'dblib:host='.$host.$_port.';dbname='.$name;
                }
                else {
                    $dsn = 'sqlsrv:Server='.$host.(empty($port) ? '':','.$_port).';Database='.$name;
                }
                break;
        }
        try {
            new PDO($dsn, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        }
        catch (PDOException $ex) {
            throw new Exception('Connection failed: ' . $ex->getMessage());
        }
    }

    /**
     * validateSqliteFile will establish the SQLite engine
     */
    protected function checkSqliteFile($filename)
    {
        if (file_exists($filename)) {
            return;
        }

        $directory = dirname($filename);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        new PDO('sqlite:'.$filename);
    }

    /**
     * getAvailableLocales returns available system locales
     */
    protected function getAvailableLocales()
    {
        return [
            'ar'    => [Lang::get('system::lang.locale.ar'),    'Arabic'],
            'be'    => [Lang::get('system::lang.locale.be'),    'Belarusian'],
            'bg'    => [Lang::get('system::lang.locale.bg'),    'Bulgarian'],
            'ca'    => [Lang::get('system::lang.locale.ca'),    'Catalan'],
            'cs'    => [Lang::get('system::lang.locale.cs'),    'Czech'],
            'da'    => [Lang::get('system::lang.locale.da'),    'Danish'],
            'de'    => [Lang::get('system::lang.locale.de'),    'German'],
            'el'    => [Lang::get('system::lang.locale.el'),    'Greek'],
            'en'    => [Lang::get('system::lang.locale.en'),    'English'],
            'en-au' => [Lang::get('system::lang.locale.en-au'), 'English'],
            'en-ca' => [Lang::get('system::lang.locale.en-ca'), 'English'],
            'en-gb' => [Lang::get('system::lang.locale.en-gb'), 'English'],
            'es'    => [Lang::get('system::lang.locale.es'),    'Spanish'],
            'es-ar' => [Lang::get('system::lang.locale.es-ar'), 'Spanish'],
            'et'    => [Lang::get('system::lang.locale.et'),    'Estonian'],
            'fa'    => [Lang::get('system::lang.locale.fa'),    'Persian'],
            'fi'    => [Lang::get('system::lang.locale.fi'),    'Finnish'],
            'fr'    => [Lang::get('system::lang.locale.fr'),    'French'],
            'fr-ca' => [Lang::get('system::lang.locale.fr-ca'), 'French'],
            'hu'    => [Lang::get('system::lang.locale.hu'),    'Hungarian'],
            'id'    => [Lang::get('system::lang.locale.id'),    'Indonesian'],
            'it'    => [Lang::get('system::lang.locale.it'),    'Italian'],
            'ja'    => [Lang::get('system::lang.locale.ja'),    'Japanese'],
            'kr'    => [Lang::get('system::lang.locale.kr'),    'Korean'],
            'lt'    => [Lang::get('system::lang.locale.lt'),    'Lithuanian'],
            'lv'    => [Lang::get('system::lang.locale.lv'),    'Latvian'],
            'nb-no' => [Lang::get('system::lang.locale.nb-no'), 'Norwegian'],
            'nl'    => [Lang::get('system::lang.locale.nl'),    'Dutch'],
            'pl'    => [Lang::get('system::lang.locale.pl'),    'Polish'],
            'pt-br' => [Lang::get('system::lang.locale.pt-br'), 'Portuguese'],
            'pt-pt' => [Lang::get('system::lang.locale.pt-pt'), 'Portuguese'],
            'ro'    => [Lang::get('system::lang.locale.ro'),    'Romanian'],
            'ru'    => [Lang::get('system::lang.locale.ru'),    'Russian'],
            'sk'    => [Lang::get('system::lang.locale.sk'),    'Slovak'],
            'sl'    => [Lang::get('system::lang.locale.sl'),    'Slovene'],
            'sv'    => [Lang::get('system::lang.locale.sv'),    'Swedish'],
            'th'    => [Lang::get('system::lang.locale.th'),    'Thai'],
            'tr'    => [Lang::get('system::lang.locale.tr'),    'Turkish'],
            'uk'    => [Lang::get('system::lang.locale.uk'),    'Ukrainian'],
            'vn'    => [Lang::get('system::lang.locale.vn'),    'Vietnamese'],
            'zh-cn' => [Lang::get('system::lang.locale.zh-cn'), 'Chinese'],
            'zh-tw' => [Lang::get('system::lang.locale.zh-tw'), 'Chinese'],
        ];
    }

    /**
     * getRandomKey generates a random application key
     */
    protected function getRandomKey(): string
    {
        return Str::random($this->getKeyLength(Config::get('app.cipher')));
    }

    /**
     * getKeyLength returns the supported length of a key for a cipher
     */
    protected function getKeyLength(string $cipher): int
    {
        return $cipher === 'AES-128-CBC' ? 16 : 32;
    }

    /**
     * getComposerUrl returns the endpoint for composer
     */
    protected function getComposerUrl(bool $withProtocol = true): string
    {
        return UpdateManager::instance()->getComposerUrl($withProtocol);
    }

    /**
     * injectJsonToFile merges a JSON array in to an existing JSON file.
     * Merging is useful for preserving array values.
     */
    protected function injectJsonToFile(string $filename, array $jsonArr, bool $merge = false): void
    {
        $contentsArr = file_exists($filename)
            ? json_decode(file_get_contents($filename), true)
            : [];

        $newArr = $merge
            ? array_merge_recursive($contentsArr, $jsonArr)
            : $this->mergeRecursive($contentsArr, $jsonArr);

        $content = json_encode($newArr, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

        file_put_contents($filename, $content);
    }

    /**
     * mergeRecursive substitues the native PHP array_merge_recursive to be
     * more config friendly. Scalar values are replaced instead of being
     * merged in to their own new array.
     */
    protected function mergeRecursive(array $array1, $array2)
    {
        if ($array2 && is_array($array2)) {
            foreach ($array2 as $key => $val2) {
                if (
                    is_array($val2) &&
                    (($val1 = isset($array1[$key]) ? $array1[$key] : null) !== null) &&
                    is_array($val1)
                ) {
                    $array1[$key] = $this->mergeRecursive($val1, $val2);
                }
                else {
                    $array1[$key] = $val2;
                }
            }
        }

        return $array1;
    }

    /**
     * nonInteractiveCheck will make a calculated guess if the command is running
     * in non interactive mode by how long it takes to execute
     */
    protected function nonInteractiveCheck(): bool
    {
        return (microtime(true) - LARAVEL_START) < 1;
    }
}
