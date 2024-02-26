<?php

namespace Colomuller91\LaravelLangPusher\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Concerns\PromptsForMissingInput;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\VarExporter\VarExporter;

class PushTranslationCommand extends Command
{
    use PromptsForMissingInput;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:translation {full-key?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'It generates translation with his key';

    private bool $updated = false;
    private string $prepend = '';
    private string $fullKey;
    private FilesystemAdapter|Filesystem $disk;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!$this->argument('full-key')) {
            $this->fullKey = $this->askPersistently('Provide translation full key (ex: passwords.reset)');
            if (!str_contains($this->fullKey,'.')) {
                $this->error('Provided translation key is invalid');
                die();
            }
        }


        $this->disk = Storage::disk('lang_resources');
        $res = $this->disk->directories();

        $languages = $this->choice('Chose in what language you want to add this key and translation',
            array_merge($res, ['ALL']), null, null, true);

        if (array_search('ALL', $languages) !== false) {
            $languages = $res;
        }

        foreach ($languages as $lang) {

            if (!$this->checkIfTranslationFileExists($lang)){
                continue;
            }

            $keys = explode('.', $this->fullKey);
            $file = $lang.DIRECTORY_SEPARATOR.$keys[0].'.php';
            $fullPath = $this->disk->path($lang).DIRECTORY_SEPARATOR.$keys[0].'.php';
            $fileData = require($fullPath);

            array_shift($keys);
            $result = $this->checkIndex($fileData, implode('.',$keys));

            switch ($result["code"]){
                case -1:
                    if ($this->confirm("[$file] Translation key exists: {$result["key"]} => {$result["value"]}".PHP_EOL." Overwrite?", 'y')){

                        $this->setIndexRecursive(
                            $fileData,
                            $this->askPersistently($this->getTranslationQuestion($lang)),
                            $keys
                        );
                    }
                    break;
                case -2:
                    if ($this->confirm("[$file] Translation value found before check all indexes: {$result["key"]} => {$result["value"]}".PHP_EOL." Overwrite?", 'y')){
                        $this->setIndexRecursive(
                            $fileData,
                            $this->askPersistently($this->getTranslationQuestion($lang)),
                            $keys
                        );
                    }
                    break;
                case -3:
                    if ($this->confirm("[$file] Given index \"{$result["key"]}\" is pointing to an array with keys like: {$result["value"]}".PHP_EOL." Overwrite?", 'y')){
                        $this->setIndexRecursive(
                            $fileData,
                            $this->askPersistently($this->getTranslationQuestion($lang)),
                            $keys
                        );
                    }
                    break;

                case 1:
                    $this->setIndexRecursive(
                        $fileData,
                        $this->askPersistently($this->getTranslationQuestion($lang)),
                        $keys
                    );
                    break;

            }

            if ($this->updated) {
                file_put_contents($fullPath, $this->prepend.VarExporter::export($fileData).';');
            }

        }
        return 0;
    }


    // -1 key exists
    // -2 key exists but user wants to append more levels
    // -3 key exists but is an array and user wants to place a string
    // 1  key can be pushed normally
    /**
     * Check if index not exists, exist and is an end key, exists and is NOT an end key
     *
     * @param $array
     * @param $index
     * @return array
     */
    private function checkIndex($array, $index): array {
        $indexes = explode('.', $index);
        $value = $array;
        $walked = [];
        foreach ($indexes as $idx) {
            $walked[] = $idx;
            if (key_exists($idx, $value)) {
                if (is_string($value[$idx])) {
                    if (count($walked) != count($indexes)) {
                        return ["code" => -2, "key" => implode('.', $walked), "value" => $value[$idx]]; //string value found before last index key
                    } else {
                        return ["code" => -1, "key" => implode('.', $walked), "value" => $value[$idx]]; //key exists
                    }
                }
                if (is_array($value[$idx]) && sizeof($walked) === sizeof($indexes)) {
                    $returnKeys = array_slice(array_keys($value[$idx]),0, 5);
                    return ["code" => -3, "key" => implode('.', $walked), "value" => implode(', ', $returnKeys)]; // last index key reached but the value is an array
                }
            } else {
                return ["code" => 1, "key" => implode('.', $walked), "value" => $value]; //key is available
            }
            $value = $value[$idx];
        }
        return ["code" => 1, "key" => implode('.', $walked), "value" => $value]; //key is available
    }

    private function setIndexRecursive(&$pot, $value, $indexes) {
        $this->updated = true;
        $actual = array_shift($indexes);
        if (count($indexes) > 0) {
            if (!key_exists($actual, $pot) || is_string($pot[$actual])) {
                $pot[$actual] = [];
            }
            $this->setIndexRecursive($pot[$actual], $value, $indexes);
        } else {
            $pot[$actual] = $value;
        }
        return $pot;
    }

    private function saveCommentsAndTag($path) {
        $this->prepend = explode('return', file_get_contents($path))[0]. 'return ';
    }


    public function initializeFile(string $path) {
        file_put_contents($path, "<?php".PHP_EOL.PHP_EOL."return ".VarExporter::export([]).";");
    }


    public function getTranslationQuestion(string $lang) {
        $lang = strtoupper($lang);
        return "Get translation for {$lang}:";
    }

    private function checkIfTranslationFileExists(mixed $lang) {
        $keys = explode('.', $this->fullKey);

        $file = $lang.DIRECTORY_SEPARATOR.$keys[0].'.php';

        $fullPath = $this->disk->path($lang).DIRECTORY_SEPARATOR.$keys[0].'.php';
        if (!$this->disk->exists($file)){
            echo "File $file doesn't exists".PHP_EOL;
            if ($this->confirm('Want\'s to create file?', 'y')){
                $this->initializeFile($fullPath);
            } else{
                return false;
            }
        }
        try {
            require($fullPath);
        } catch (\Error $e) {
            echo "Can't read file $file".PHP_EOL;
            return false;
        }

        $this->saveCommentsAndTag($fullPath);

        return true;
    }
}
