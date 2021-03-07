#  JSON and XML handling

Objects can be created easily out of json/xml data.

#### Example (json)

```phpt
class Product {

    #[JsonProperty]
    private string $name;

    #[JsonProperty]
    private float $price;
}


//  Create objects explicitly
$obj = ObjectCreator::createObject(Product::class, json_decode('{"name": "Pen", "price": 2.01}', true) );


// in RestController: framework create them for you
#[RequestMapping(path: "/api/v2/products", consumes: [MediaType.APPLICATION_JSON] method: [RequestMethod::POST])]
public function createProduct(
     #[RequestBody] Product $product
) {
    // ...  $product is created by framework from request
    //  if Content-Type: application/json
    //  or, Content-Type: application/xml   ,  text/xml
}

```

XML also similar, but **createObjectXml()** has to be called.

#### Example (xml)

```phpt

$obj = ObjectCreator::createObjectXml(
    Product::class, 
    '<product><name>Pen</name><price>2.00</price></product>' 
);

```

