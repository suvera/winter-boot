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



## 3. RequestParam

Attribute **#[RequestParam]** is used to bind request parameters to a method parameter in your controller.
i.e. $_GET and $_POST values.

#### Example:

```phpt

#[RequestMapping(path: "/divide", method: [RequestMethod::POST])]
public function divide(
    #[RequestParam] int $a,
    #[RequestParam] int $b,
): float {

}

```


## 4. RequestBody

The **#[RequestBody]** attribute indicates that a method parameter should be bound to the value of the whole HTTP request body.

Using this you can map whole JSON body to a Class.

#### Example:

```phpt

#[RequestMapping(path: "/calc/add", method: [RequestMethod::POST])]
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

#[RequestMapping(path: "/hello/{name}", method: [RequestMethod::GET])]
public function sayHello2(
    #[PathVariable] string $name
): string {
    return 'Hello, ' . $name;
}

```
