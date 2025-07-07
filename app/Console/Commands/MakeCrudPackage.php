<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class MakeCrudPackage extends Command
{
    protected $signature = 'make:crud 
    {name} 
    {--schema=} 
    {--api-route=} 
    {--controller-route=}';
    protected $description = 'Generate Model, Migration, Controller, Service, and DTOs';

    public function handle()
    {
        $schema = [];

        if ($this->option('schema')) {
            $schemaFile = base_path($this->option('schema'));

            if (file_exists($schemaFile)) {
                $this->info("🔍 Using schema from: {$schemaFile}");
                $schema = include $schemaFile;

                if (!is_array($schema)) {
                    $this->error("❌ Schema file must return an array.");
                    return;
                }
            } else {
                $this->error("❌ Schema file not found: {$schemaFile}");
                return;
            }
        }

        $name = $this->argument('name');
        $modelName = Str::studly($name);
        $tableName = Str::snake(Str::pluralStudly($modelName));
        $serviceName = "{$modelName}Service";

        $controllerSubPath = trim($this->option('controller-route') ?? '', '\\/');
        $controllerNamespace = 'App\\Http\\Controllers' . ($controllerSubPath ? '\\' . str_replace('/', '\\', $controllerSubPath) : '');
        $controllerDirectory = app_path('Http/Controllers' . ($controllerSubPath ? '/' . str_replace('\\', '/', $controllerSubPath) : ''));

        // 1. Model
        $this->info("Creating model and migration...");
        Artisan::call("make:model $modelName -m");
        $this->appendModelMethods($modelName);
        if (!empty($schema[$modelName])) {
            $this->updateMigrationFile($modelName, $schema[$modelName]);
        }


        // 2. Controller
        $this->info("Creating controller...");
        $this->createController($modelName, $controllerNamespace, $controllerDirectory);

        // 3. Service
        $this->info("Creating service...");
        $this->createService($modelName);

        // 4. DTOs
        $this->info("Creating DTOs...");
        $this->createDTOs($modelName, $schema[$modelName] ?? []);

        // 5. Response Helper
        $this->info("Creating Response Helper...");
        $this->generateResponsesHelper();

        // 6. Resource
        $this->info("Creating Resource...");
        Artisan::call("make:resource {$modelName}Resource");

        // 7. Route
        $this->info("Adding route...");
        $routePath = $this->option('api-route');
        $this->addApiResourceRoute($modelName, $routePath, $controllerNamespace);

        
        $this->info("✅ CRUD Package for $modelName created successfully!");
    }


    protected function appendModelMethods($modelName)
    {
        $modelPath = app_path("Models/{$modelName}.php");

        if (!file_exists($modelPath)) {
            $this->error("Model file not found: $modelPath");
            return;
        }

        $methodContent = <<<PHP

    // Custom CRUD methods using DTOs
    public static function store{$modelName}(\$data)
    {
        return self::create(\$data->toCreate());
    }

    public static function update{$modelName}(\$dto)
    {
        return self::findOrFail(\$dto->id)->update(\$dto->toUpdate());
    }

    public static function delete{$modelName}(\$id)
    {
        return self::findOrFail(\$id)->delete();
    }

    public static function list{$modelName}s(\$dto)
    {
        // Add filtering logic if needed
        return self::query()->paginate();
    }

PHP;

        // Append above methods before the last closing brace `}`
        $modelContent = file_get_contents($modelPath);

        if (str_contains($modelContent, 'function store' . $modelName)) {
            $this->info("Methods already exist in model, skipping append.");
            return;
        }

        $modelContent = preg_replace('/}\s*$/', rtrim($methodContent) . "\n}", $modelContent);
        file_put_contents($modelPath, $modelContent);
        $this->info("✔️  Methods added to $modelName model.");
    }


    protected function createService($modelName)
    {
        $servicePath = app_path("Services/{$modelName}Service.php");

        if (!is_dir(dirname($servicePath))) {
            mkdir(dirname($servicePath), 0755, true);
        }

        $content = <<<PHP
<?php

namespace App\Services;

use App\DTOs\Service\\{$modelName}\\Store{$modelName}DTO;
use App\DTOs\Service\\{$modelName}\\Update{$modelName}DTO;
use App\DTOs\Service\\{$modelName}\\Delete{$modelName}DTO;
use App\DTOs\Service\\{$modelName}\\List{$modelName}DTO;
use App\Models\\{$modelName};
use Illuminate\Support\Facades\DB;

class {$modelName}Service
{
    public function list(List{$modelName}DTO \$dto)
    {
        return {$modelName}::list{$modelName}s(\$dto);
    }

    public function store(Store{$modelName}DTO \$dto)
    {
        return DB::transaction(fn () =>
            {$modelName}::store{$modelName}(\$dto->toArray())
        );
    }

    public function update(Update{$modelName}DTO \$dto)
    {
        return DB::transaction(fn () =>
            {$modelName}::update{$modelName}(\$dto)
        );
    }

    public function delete(Delete{$modelName}DTO \$dto)
    {
        return DB::transaction(fn () =>
            {$modelName}::delete{$modelName}(\$dto->id)
        );
    }
}
PHP;

        file_put_contents($servicePath, $content);
    }

    protected function createController(string $modelName, string $controllerNamespace, string $controllerDirectory)
    {
        if (!is_dir($controllerDirectory)) {
            mkdir($controllerDirectory, 0755, true);
        }

        $controllerPath = "{$controllerDirectory}/{$modelName}Controller.php";

        $variableName = lcfirst($modelName);
        $dtoNamespace = "App\\DTOs\\Service\\{$modelName}";
        $resourceName = "{$modelName}Resource";

        $content = <<<PHP
<?php

namespace {$controllerNamespace};

use {$dtoNamespace}\\List{$modelName}DTO;
use {$dtoNamespace}\\Store{$modelName}DTO;
use {$dtoNamespace}\\Update{$modelName}DTO;
use {$dtoNamespace}\\Delete{$modelName}DTO;
use App\Http\Controllers\Controller;
use App\Helpers\ResponsesHelper;
use App\Http\Resources\\{$resourceName};
use App\Services\\{$modelName}Service;
use Illuminate\Http\Request;

class {$modelName}Controller extends Controller
{
    protected {$modelName}Service \${$variableName};

    public function __construct({$modelName}Service \${$variableName})
    {
        \$this->{$variableName} = \${$variableName};
    }

    public function index(Request \$request)
    {
        \$items = \$this->{$variableName}->list(new List{$modelName}DTO(\$request->all()));
        return ResponsesHelper::returnResource(
            {$resourceName}::collection(\$items),
            trans('defaults.response.success.list_successfully')
        );
    }

    public function store(Request \$request)
    {
        \$item = \$this->{$variableName}->store(new Store{$modelName}DTO(\$request->all()));
        return ResponsesHelper::returnCreatedResource(
            {$resourceName}::make(\$item),
            trans('defaults.response.success.create_successfully')
        );
    }

    public function update(Request \$request, \$id)
    {
        \$this->{$variableName}->update(new Update{$modelName}DTO(array_merge(\$request->all(), ['id' => \$id])));
        return ResponsesHelper::returnSuccessMessage(
            trans('defaults.response.success.update_successfully')
        );
    }

    public function destroy(\$id)
    {
        \$this->{$variableName}->delete(new Delete{$modelName}DTO(['id' => \$id]));
        return ResponsesHelper::returnSuccessMessage(
            trans('defaults.response.success.delete_successfully')
        );
    }
}
PHP;

        file_put_contents($controllerPath, $content);
        $this->info("✔️  Controller file written to: {$controllerPath}");
    }


    protected function buildRulesFromSchema(array $schema): string
    {
        return collect($schema)
            ->map(fn($rule, $field) => "            '$field' => '$rule',")
            ->implode("\n");
    }

    protected function createDTOs(string $modelName, array $schema = [])
    {
        $types = ['Store', 'Update', 'Delete', 'List'];
        $serviceBasePath = app_path("DTOs/Service/{$modelName}");
        $modelBasePath = app_path("DTOs/Model/{$modelName}");

        if (!is_dir($serviceBasePath)) {
            mkdir($serviceBasePath, 0755, true);
        }

        if (!is_dir($modelBasePath)) {
            mkdir($modelBasePath, 0755, true);
        }

        foreach ($types as $type) {
            $dtoName = "{$type}{$modelName}DTO";

            $this->generateDTOStub($modelBasePath, $dtoName, "App\\DTOs\\Model\\{$modelName}");

            $method = "generate{$type}ServiceDTO";
            if (method_exists($this, $method)) {
                $schemaForType = in_array($type, ['Store', 'Update']) ? $schema : [];
                $this->{$method}($serviceBasePath, $dtoName, "App\\DTOs\\Service\\{$modelName}", $schemaForType);
            } else {
                $this->generateDTOStub($serviceBasePath, $dtoName, "App\\DTOs\\Service\\{$modelName}");
            }
        }
    }

    protected function generateDTOStub(string $path, string $dtoName, string $namespace)
    {
        $content = <<<PHP
<?php

namespace {$namespace};

use WendellAdriel\ValidatedDTO\ValidatedDTO;
use WendellAdriel\ValidatedDTO\Concerns\EmptyDefaults;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;

class {$dtoName} extends ValidatedDTO
{
    use EmptyDefaults, EmptyCasts;

    protected function rules(): array
    {
        return [];
    }
}
PHP;

        file_put_contents("{$path}/{$dtoName}.php", $content);
    }

    protected function generateListServiceDTO($path, $dtoName, $namespace, array $schema = [])
    {
        $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\Validation\Validator;
use WendellAdriel\ValidatedDTO\ValidatedDTO;
use WendellAdriel\ValidatedDTO\Concerns\EmptyDefaults;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;

class {$dtoName} extends ValidatedDTO
{
    use EmptyDefaults, EmptyCasts;

    public \$perPage;
    public ?int \$page;

    protected function rules(): array
    {
        return [
            'per_page' => 'nullable|int',
            'page' => 'nullable|int',
        ];
    }

    public function after(Validator \$validator): void
    {
        if (count(\$validator->failed())) {
            \$this->failedValidation();
        }

        \$data = \$validator->validated();
        \$this->perPage = \$data['per_page'] ?? 15;
        \$this->page = \$data['page'] ?? 1;
    }
}
PHP;

        file_put_contents("{$path}/{$dtoName}.php", $content);
    }

    protected function generateStoreServiceDTO($path, $dtoName, $namespace, array $schema = [])
    {
        $rules = $this->buildRulesFromSchema($schema);

        $content = <<<PHP
    <?php
    
    namespace {$namespace};
    
    use Illuminate\Validation\Validator;
    use WendellAdriel\ValidatedDTO\ValidatedDTO;
    use WendellAdriel\ValidatedDTO\Concerns\EmptyDefaults;
    use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;
    
    class {$dtoName} extends ValidatedDTO
    {
        use EmptyDefaults, EmptyCasts;

        protected function rules(): array
        {
            return [
    {$rules}
            ];
        }
    
        public function after(Validator \$validator): void
        {
            if (count(\$validator->failed())) {
                \$this->failedValidation();
            }
        }
    
        public function toCreate(): array
        {
            \$collection = collect(\$this->validatedData)->reject(function (\$value, \$key) {
                if (\$value === null) {
                    return true;
                }
                return false;
            })->toArray();
    
            return \$collection;
        }
    }
    PHP;

        file_put_contents("{$path}/{$dtoName}.php", $content);
    }

    protected function generateUpdateServiceDTO($path, $dtoName, $namespace, array $schema = [])
    {
        $rules = $this->buildRulesFromSchema(array_merge(['id' => 'required|int'], $schema));

        $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\Validation\Validator;
use WendellAdriel\ValidatedDTO\ValidatedDTO;
use WendellAdriel\ValidatedDTO\Concerns\EmptyDefaults;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;

class {$dtoName} extends ValidatedDTO
{
    use EmptyDefaults, EmptyCasts;

    protected function rules(): array
    {
        return [
{$rules}
        ];
    }

    public function toUpdate(): array
    {
        return collect(\$this->validatedData)->reject(function (\$value, \$key) {
            if (\$value === null || in_array(\$key, ['id', 'profile_types_ids'])) {
                return true;
            }
            return false;
        })->toArray();
    }
}
PHP;

        file_put_contents("{$path}/{$dtoName}.php", $content);
    }

    protected function generateDeleteServiceDTO($path, $dtoName, $namespace, array $schema = [])
    {
        $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\Validation\Validator;
use WendellAdriel\ValidatedDTO\ValidatedDTO;
use WendellAdriel\ValidatedDTO\Concerns\EmptyDefaults;
use WendellAdriel\ValidatedDTO\Concerns\EmptyCasts;

class {$dtoName} extends ValidatedDTO
{
    use EmptyDefaults, EmptyCasts;

    protected function rules(): array
    {
        return [
            'id' => 'required|int',
        ];
    }
}
PHP;

        file_put_contents("{$path}/{$dtoName}.php", $content);
    }


    protected function generateResponsesHelper()
    {
        $helperPath = app_path('Helpers');
        $filePath = $helperPath . '/ResponsesHelper.php';

        if (!is_dir($helperPath)) {
            mkdir($helperPath, 0755, true);
        }

        if (!file_exists($filePath)) {
            file_put_contents(
                $filePath,
                <<<PHP
<?php

namespace App\Helpers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Validator;

class ResponsesHelper
{
    public static function returnError(string \$msg, int \$code = 422)
    {
        return response()->json([
            'code' => \$code,
            'message' => \$msg,
            'success' => false,
            'status' => \$code,
        ])->setStatusCode(\$code);
    }

    public static function returnSuccessMessage(string \$msg = "", int \$code = 200)
    {
        return response()->json([
            'code' => \$code,
            'message' => \$msg,
            'success' => true,
            'status' => \$code,
        ])->setStatusCode(\$code);
    }

    public static function returnResource(JsonResource \$jsonResource, string \$msg = "", int \$code = 200)
    {
        return \$jsonResource->additional([
            'code' => \$code,
            'message' => \$msg,
            'success' => true,
            'status' => \$code,
        ]);
    }

    public static function returnCreatedResource(JsonResource \$jsonResource, string \$msg = "", int \$code = 201)
    {
        return \$jsonResource->additional([
            'code' => \$code,
            'message' => \$msg,
            'success' => true,
            'status' => \$code,
        ]);
    }

    public static function returnData(array \$dataArr = [], string \$msg = "", int \$code = 200)
    {
        return response()->json([
            'data' => \$dataArr,
            'code' => \$code,
            'message' => \$msg,
            'success' => true,
            'status' => \$code,
        ]);
    }

    public static function returnValidationError(Validator \$validator, string \$msg = '', int \$code = 422)
    {
        return response()->json([
            'errors' => \$validator->errors(),
            'code' => \$code,
            'message' => \$validator->errors()->first(),
            'success' => false,
            'status' => \$code,
        ]);
    }

    public static function returnArrayErrors(array \$errors, string \$msg, int \$code = 422)
    {
        return response()->json([
            'errors' => \$errors,
            'code' => \$code,
            'message' => \$msg,
            'success' => false,
            'status' => \$code,
        ]);
    }

    public static function returnObject(\$dataObj, string \$msg = "", int \$code = 200)
    {
        return response()->json([
            'data' => \$dataObj,
            'code' => \$code,
            'message' => \$msg,
            'success' => true,
            'status' => \$code,
        ]);
    }
}
PHP
            );

            $this->info('✅ Created: app/Helpers/ResponsesHelper.php');
        } else {
            $this->info('ℹ️  ResponsesHelper.php already exists. Skipped.');
        }
    }

    protected function addApiResourceRoute(string $modelName, string $routePath = null, string $controllerNamespace = 'App\\Http\\Controllers')
    {
        $routeFile = $routePath ?? base_path('routes/api.php');

        if (!file_exists($routeFile)) {
            $this->error("❌ Route file not found at: {$routeFile}");
            return;
        }

        $routeName = Str::kebab(Str::pluralStudly($modelName)); // e.g., products
        $controllerFQN = "{$controllerNamespace}\\{$modelName}Controller";
        $routeDefinition = "Route::apiResource('{$routeName}', \\{$controllerFQN}::class);";

        $existingRoutes = file_get_contents($routeFile);
        if (str_contains($existingRoutes, $controllerFQN)) {
            $this->info("ℹ️  Route for {$controllerFQN} already exists in {$routeFile}");
            return;
        }

        // Append route to the file
        file_put_contents($routeFile, PHP_EOL . $routeDefinition . PHP_EOL, FILE_APPEND);
        $this->info("✅ Route added to {$routeFile}");
    }

    protected function updateMigrationFile(string $modelName, array $fields): void
    {
        $timestampPrefix = now()->format('Y_m_d_His');
        $migrationName = "create_" . Str::snake(Str::pluralStudly($modelName)) . "_table";

        $migrationsPath = database_path('migrations');
        $files = glob($migrationsPath . "/*{$migrationName}.php");

        if (empty($files)) {
            $this->error("❌ Migration file for {$modelName} not found.");
            return;
        }

        $migrationFile = $files[0];

        $columnStub = '';
        foreach ($fields as $field => $rule) {
            $columnStub .= '            ' . $this->generateColumnLine($field, $rule) . PHP_EOL;
        }

        $content = file_get_contents($migrationFile);
        $content = preg_replace(
            '/(Schema::create\(.*?function \(Blueprint \$table\) \{)(.*?)\n(\s*\$table->timestamps\(\);)/s',
            '$1$2' . "\n" . $columnStub . '$3',
            $content
        );

        file_put_contents($migrationFile, $content);
        $this->info("🛠️  Migration updated: $migrationFile");
    }

    protected function generateColumnLine(string $field, string $rule): string
    {
        $isNullable = str_contains($rule, 'nullable');
        $type = 'string';

        if (str_contains($rule, 'integer'))
            $type = 'integer';
        elseif (str_contains($rule, 'numeric'))
            $type = 'float';
        elseif (str_contains($rule, 'boolean'))
            $type = 'boolean';
        elseif (str_contains($rule, 'date'))
            $type = 'date';

        $method = "\$table->{$type}('{$field}')";
        if ($isNullable) {
            $method .= '->nullable()';
        }

        return $method . ';';
    }




}
