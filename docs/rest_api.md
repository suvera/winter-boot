# REST API

----

REST API Development related attributes

## 1. RestController

This attribute is used at the class level. The **#[RestController]** annotation marks the class as a controller that handles REST requests.

```phpt

#[RestController]
class UserController {
}

```

## 2. RequestMapping

This attribute is used both at class and method level. The **#[RequestMapping]** attribute is used to map web requests onto specific handler classes and handler methods.

When **#[RequestMapping]** is used on class level it creates a base URI for all methods defined in that class. When this attribute is used on methods it will give you the URI on which the handler methods will be executed.

#### Options:

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
path | Yes | | URI value of the request
method |  | All Methods | List of HTTP methods
name |  | | Name of the request mapping
consumes |  | | List of Media Types

#### Example:

```phpt
#[RequestMapping(path: "/Users", method: [RequestMethod::POST])]
public function createUser(): ResponseEntity {
}


#[RequestMapping(path: "/Users", method: [RequestMethod::GET])]
public function listUsers(): ResponseEntity {
}


#[RequestMapping(path: "/Users/{id}", method: [RequestMethod::GET])]
public function getUser(#[PathVariable] string $id): ResponseEntity {
}

```

### Similar Attributes

**1. #[DeleteMapping]**

**2. #[GetMapping]**

**3. #[PatchMapping]**

**4. #[PostMapping]**

**5. #[PutMapping]**


## 3. RequestParam

Attribute **#[RequestParam]** is used to bind request parameters to a method parameter in your controller.
i.e. $_GET and $_POST values.

#### Example:

```phpt

#[PostMapping(path: "/divide")]
public function divide(
    #[RequestParam] int $a,
    #[RequestParam] int $b,
): float {

}

```

#### Attribute Options

Attribute **#[RequestParam]** has more options.

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
name | Yes | | Name of the Request Parameter
required |  | true | Parameter is mandatory
source |  | 'request' | Source of this parameter value, could be one of ['request', 'get', 'post', 'cookie', 'header']
defaultValue |  |  | Default Value


**source** values can be one of following values

source | Description
------------ | ------------
request | Value can be from url QUERY and POST
get | Value is from url QUERY
post | Value is from POST
cookie | Value is from http Cookie
header | Value is from Http header



## 4. RequestBody

The **#[RequestBody]** attribute indicates that a method parameter should be bound to the value of the whole HTTP request body.

Using this you can map whole JSON body to a Class.

#### Example:

```phpt

#[PostMapping(path: "/calc/add")]
public function sum(
    #[RequestBody] AddRequest $request
): int {
    return $this->service->add($request->a, $request->b);
}

-----

class AddRequest {
   private int $a;
   private int $b;
}


// Request body - in json
{"a": 10, "b": 20}

// Request body - url encoded
a=10&b=20

```


## 5. PathVariable

Attribute **#[PathVariable]** indicates that a method parameter should be bound to a URI template variable.

#### Example:

```phpt

#[GetMapping(path: "/hello/{name}")]
public function sayHello2(
    #[PathVariable] string $name
): string {
    return 'Hello, ' . $name;
}

```


# Error Handling

----

Define a error controller


It can be done in multiple ways

### Approach - 1
```phpt

#[Component('errorController')]
class MyErrorControllerImpl implements ErrorController {
}
```

### Approach - 2
```phpt

#[Bean('errorController')]
public function getErrorController(): ErrorController {
    return new MyErrorControllerImpl();
}
```


# 