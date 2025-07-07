# Laravel CRUD Generator Command

This package provides an Artisan command `make:crud` to quickly scaffold a complete CRUD (Create, Read, Update, Delete) module for your Laravel application. It generates a Model, Migration, Controller, Service, DTOs, and an API Resource, following a structured and scalable pattern.

## Features

-   Generates a full CRUD setup with a single command.
-   Uses Data Transfer Objects (DTOs) for validation and data handling.
-   Creates a dedicated Service layer for business logic.
-   Automatically updates the migration file based on a schema definition.
-   Adds API resource routes automatically.
-   Highly customizable through options.

## Dependencies

This command relies on the `wendelladriel/validated-dto` package for DTO validation. Before using the command, make sure to install it:

```bash
composer require wendelladriel/validated-dto
```

## Usage

To generate a new CRUD module, use the following Artisan command:

```bash
php artisan make:crud {name} {--schema=} {--api-route=} {--controller-route=}
```

### Arguments

-   `{name}`: The name of the resource (e.g., `Product`). This will be used to generate the class names.

### Options

-   `--schema=<path>`: (Optional) The path to a schema file. The schema file defines the validation rules and database columns for the model.
-   `--api-route=<path>`: (Optional) The path to the API routes file where the resource route will be added. Defaults to `routes/api.php`.
-   `--controller-route=<path>`: (Optional) A sub-path for the controller's namespace and directory. For example, `V1/Admin` will place the controller in `App/Http/Controllers/V1/Admin`.

### Basic Example

This command will generate the CRUD files for a "Product" resource.

```bash
php artisan make:crud Product
```

### Advanced Example with Schema

This command will generate the CRUD files for a "Post" resource, using a schema definition for validation and migration fields.

```bash
php artisan make:crud Post --schema=app/Schemas/PostSchema.php
```

## What It Generates

The `make:crud` command creates the following files and components:

1.  **Model**: `app/Models/{Name}.php`
    -   Includes standard Eloquent setup.
    -   Adds helper methods for CRUD operations (`store{Name}`, `update{Name}`, etc.).

2.  **Migration**: `database/migrations/..._create_{name_plural}_table.php`
    -   Creates the database table for the model.
    -   If a `--schema` is provided, it automatically adds the columns to the migration file.

3.  **Controller**: `app/Http/Controllers/{Name}Controller.php`
    -   A standard RESTful controller with `index`, `store`, `update`, and `destroy` methods.
    -   Uses the generated Service and DTOs for handling requests.

4.  **Service**: `app/Services/{Name}Service.php`
    -   Contains the business logic for the CRUD operations, keeping the controller thin.

5.  **DTOs (Data Transfer Objects)**: `app/DTOs/Service/{Name}/`
    -   `Store{Name}DTO.php`: For validating store requests.
    -   `Update{Name}DTO.php`: For validating update requests.
    -   `Delete{Name}DTO.php`: For validating delete requests.
    -   `List{Name}DTO.php`: For handling listing/pagination parameters.

6.  **API Resource**: `app/Http/Resources/{Name}Resource.php`
    -   For transforming the model into a JSON response.

7.  **Route**:
    -   Appends an `apiResource` route to your API routes file (e.g., `routes/api.php`).

8.  **Response Helper**: `app/Helpers/ResponsesHelper.php`
    -   A helper class for generating standardized JSON API responses. This is only created if it doesn't already exist.

## The Schema File

The schema file allows you to define the fields for your model. It should be a PHP file that returns an array where keys are the field names and values are their Laravel validation rules. The command uses these rules to generate DTOs and migration columns.

**Example Schema: `app/Schemas/PostSchema.php`**

```php
<?php

// app/Schemas/PostSchema.php

return [
    'Post' => [
        'title' => 'required|string|max:255',
        'body' => 'required|string',
        'is_published' => 'sometimes|boolean',
        'user_id' => 'required|integer|exists:users,id',
    ],
    // You can define other model schemas here
];
```

The command will look for the key that matches the model name (e.g., `Post`).

## Customization

-   **Controller Location**: Use the `--controller-route` option to organize controllers into subdirectories. For example, `--controller-route=Api/V1` will create the controller at `app/Http/Controllers/Api/V1/{Name}Controller.php`.
-   **Route File**: Use the `--api-route` option to specify a different file for adding the API routes, like `routes/admin.php`.
-   **Generated Code**: After running the command, you are free to modify any of the generated files to fit your specific needs.

## Author

This CRUD generator was created by [Mohammed Hassan](https://github.com/MohammedAbdo2344).

