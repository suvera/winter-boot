<?php
declare(strict_types=1);

namespace dev\winterframework\web\http;

final class HttpStatus {
    public static HttpStatus $CONTINUE;
    public static HttpStatus $SWITCHING_PROTOCOLS;
    public static HttpStatus $PROCESSING;
    public static HttpStatus $CHECKPOINT;
    public static HttpStatus $OK;
    public static HttpStatus $CREATED;
    public static HttpStatus $ACCEPTED;
    public static HttpStatus $NON_AUTHORITATIVE_INFORMATION;
    public static HttpStatus $NO_CONTENT;
    public static HttpStatus $RESET_CONTENT;
    public static HttpStatus $PARTIAL_CONTENT;
    public static HttpStatus $MULTI_STATUS;
    public static HttpStatus $ALREADY_REPORTED;
    public static HttpStatus $IM_USED;
    public static HttpStatus $MULTIPLE_CHOICES;
    public static HttpStatus $MOVED_PERMANENTLY;
    public static HttpStatus $FOUND;
    public static HttpStatus $MOVED_TEMPORARILY;
    public static HttpStatus $SEE_OTHER;
    public static HttpStatus $NOT_MODIFIED;
    public static HttpStatus $USE_PROXY;
    public static HttpStatus $TEMPORARY_REDIRECT;
    public static HttpStatus $PERMANENT_REDIRECT;
    public static HttpStatus $BAD_REQUEST;
    public static HttpStatus $UNAUTHORIZED;
    public static HttpStatus $PAYMENT_REQUIRED;
    public static HttpStatus $FORBIDDEN;
    public static HttpStatus $NOT_FOUND;
    public static HttpStatus $METHOD_NOT_ALLOWED;
    public static HttpStatus $NOT_ACCEPTABLE;
    public static HttpStatus $PROXY_AUTHENTICATION_REQUIRED;
    public static HttpStatus $REQUEST_TIMEOUT;
    public static HttpStatus $CONFLICT;
    public static HttpStatus $GONE;
    public static HttpStatus $LENGTH_REQUIRED;
    public static HttpStatus $PRECONDITION_FAILED;
    public static HttpStatus $PAYLOAD_TOO_LARGE;
    public static HttpStatus $REQUEST_ENTITY_TOO_LARGE;
    public static HttpStatus $URI_TOO_LONG;
    public static HttpStatus $REQUEST_URI_TOO_LONG;
    public static HttpStatus $UNSUPPORTED_MEDIA_TYPE;
    public static HttpStatus $REQUESTED_RANGE_NOT_SATISFIABLE;
    public static HttpStatus $EXPECTATION_FAILED;
    public static HttpStatus $I_AM_A_TEAPOT;
    public static HttpStatus $INSUFFICIENT_SPACE_ON_RESOURCE;
    public static HttpStatus $METHOD_FAILURE;
    public static HttpStatus $DESTINATION_LOCKED;
    public static HttpStatus $UNPROCESSABLE_ENTITY;
    public static HttpStatus $LOCKED;
    public static HttpStatus $FAILED_DEPENDENCY;
    public static HttpStatus $UPGRADE_REQUIRED;
    public static HttpStatus $PRECONDITION_REQUIRED;
    public static HttpStatus $TOO_MANY_REQUESTS;
    public static HttpStatus $REQUEST_HEADER_FIELDS_TOO_LARGE;
    public static HttpStatus $UNAVAILABLE_FOR_LEGAL_REASONS;
    public static HttpStatus $INTERNAL_SERVER_ERROR;
    public static HttpStatus $NOT_IMPLEMENTED;
    public static HttpStatus $BAD_GATEWAY;
    public static HttpStatus $SERVICE_UNAVAILABLE;
    public static HttpStatus $GATEWAY_TIMEOUT;
    public static HttpStatus $HTTP_VERSION_NOT_SUPPORTED;
    public static HttpStatus $VARIANT_ALSO_NEGOTIATES;
    public static HttpStatus $INSUFFICIENT_STORAGE;
    public static HttpStatus $LOOP_DETECTED;
    public static HttpStatus $BANDWIDTH_LIMIT_EXCEEDED;
    public static HttpStatus $NOT_EXTENDED;
    public static HttpStatus $NETWORK_AUTHENTICATION_REQUIRED;

    /**
     * @var HttpStatus[]
     */
    private static array $byCode = [];

    public function __construct(
        private int $value,
        private string $reasonPhrase
    ) {
        self::$byCode[$this->value] = $this;
    }

    public function getValue(): int {
        return $this->value;
    }

    public function getReasonPhrase(): string {
        return $this->reasonPhrase;
    }

    public static function getStatus(int $code): HttpStatus {
        return self::$byCode[$code];
    }

    public static function init(): void {
        self::$CONTINUE = new HttpStatus(100, "Continue");
        self::$SWITCHING_PROTOCOLS = new HttpStatus(101, "Switching Protocols");
        self::$PROCESSING = new HttpStatus(102, "Processing");
        self::$CHECKPOINT = new HttpStatus(103, "Checkpoint");
        self::$OK = new HttpStatus(200, "OK");
        self::$CREATED = new HttpStatus(201, "Created");
        self::$ACCEPTED = new HttpStatus(202, "Accepted");
        self::$NON_AUTHORITATIVE_INFORMATION = new HttpStatus(203, "Non-Authoritative Information");
        self::$NO_CONTENT = new HttpStatus(204, "No Content");
        self::$RESET_CONTENT = new HttpStatus(205, "Reset Content");
        self::$PARTIAL_CONTENT = new HttpStatus(206, "Partial Content");
        self::$MULTI_STATUS = new HttpStatus(207, "Multi-Status");
        self::$ALREADY_REPORTED = new HttpStatus(208, "Already Reported");
        self::$IM_USED = new HttpStatus(226, "IM Used");
        self::$MULTIPLE_CHOICES = new HttpStatus(300, "Multiple Choices");
        self::$MOVED_PERMANENTLY = new HttpStatus(301, "Moved Permanently");
        self::$FOUND = new HttpStatus(302, "Found");
        self::$MOVED_TEMPORARILY = new HttpStatus(302, "Moved Temporarily");
        self::$SEE_OTHER = new HttpStatus(303, "See Other");
        self::$NOT_MODIFIED = new HttpStatus(304, "Not Modified");
        self::$USE_PROXY = new HttpStatus(305, "Use Proxy");
        self::$TEMPORARY_REDIRECT = new HttpStatus(307, "Temporary Redirect");
        self::$PERMANENT_REDIRECT = new HttpStatus(308, "Permanent Redirect");
        self::$BAD_REQUEST = new HttpStatus(400, "Bad Request");
        self::$UNAUTHORIZED = new HttpStatus(401, "Unauthorized");
        self::$PAYMENT_REQUIRED = new HttpStatus(402, "Payment Required");
        self::$FORBIDDEN = new HttpStatus(403, "Forbidden");
        self::$NOT_FOUND = new HttpStatus(404, "Not Found");
        self::$METHOD_NOT_ALLOWED = new HttpStatus(405, "Method Not Allowed");
        self::$NOT_ACCEPTABLE = new HttpStatus(406, "Not Acceptable");
        self::$PROXY_AUTHENTICATION_REQUIRED = new HttpStatus(407, "Proxy Authentication Required");
        self::$REQUEST_TIMEOUT = new HttpStatus(408, "Request Timeout");
        self::$CONFLICT = new HttpStatus(409, "Conflict");
        self::$GONE = new HttpStatus(410, "Gone");
        self::$LENGTH_REQUIRED = new HttpStatus(411, "Length Required");
        self::$PRECONDITION_FAILED = new HttpStatus(412, "Precondition Failed");
        self::$PAYLOAD_TOO_LARGE = new HttpStatus(413, "Payload Too Large");
        self::$REQUEST_ENTITY_TOO_LARGE = new HttpStatus(413, "Request Entity Too Large");
        self::$URI_TOO_LONG = new HttpStatus(414, "URI Too Long");
        self::$REQUEST_URI_TOO_LONG = new HttpStatus(414, "Request-URI Too Long");
        self::$UNSUPPORTED_MEDIA_TYPE = new HttpStatus(415, "Unsupported Media Type");
        self::$REQUESTED_RANGE_NOT_SATISFIABLE = new HttpStatus(416, "Requested range not satisfiable");
        self::$EXPECTATION_FAILED = new HttpStatus(417, "Expectation Failed");
        self::$I_AM_A_TEAPOT = new HttpStatus(418, "I'm a teapot");
        self::$INSUFFICIENT_SPACE_ON_RESOURCE = new HttpStatus(419, "Insufficient Space On Resource");
        self::$METHOD_FAILURE = new HttpStatus(420, "Method Failure");
        self::$DESTINATION_LOCKED = new HttpStatus(421, "Destination Locked");
        self::$UNPROCESSABLE_ENTITY = new HttpStatus(422, "Unprocessable Entity");
        self::$LOCKED = new HttpStatus(423, "Locked");
        self::$FAILED_DEPENDENCY = new HttpStatus(424, "Failed Dependency");
        self::$UPGRADE_REQUIRED = new HttpStatus(426, "Upgrade Required");
        self::$PRECONDITION_REQUIRED = new HttpStatus(428, "Precondition Required");
        self::$TOO_MANY_REQUESTS = new HttpStatus(429, "Too Many Requests");
        self::$REQUEST_HEADER_FIELDS_TOO_LARGE = new HttpStatus(431, "Request Header Fields Too Large");
        self::$UNAVAILABLE_FOR_LEGAL_REASONS = new HttpStatus(451, "Unavailable For Legal Reasons");
        self::$INTERNAL_SERVER_ERROR = new HttpStatus(500, "Internal Server Error");
        self::$NOT_IMPLEMENTED = new HttpStatus(501, "Not Implemented");
        self::$BAD_GATEWAY = new HttpStatus(502, "Bad Gateway");
        self::$SERVICE_UNAVAILABLE = new HttpStatus(503, "Service Unavailable");
        self::$GATEWAY_TIMEOUT = new HttpStatus(504, "Gateway Timeout");
        self::$HTTP_VERSION_NOT_SUPPORTED = new HttpStatus(505, "HTTP Version not supported");
        self::$VARIANT_ALSO_NEGOTIATES = new HttpStatus(506, "Variant Also Negotiates");
        self::$INSUFFICIENT_STORAGE = new HttpStatus(507, "Insufficient Storage");
        self::$LOOP_DETECTED = new HttpStatus(508, "Loop Detected");
        self::$BANDWIDTH_LIMIT_EXCEEDED = new HttpStatus(509, "Bandwidth Limit Exceeded");
        self::$NOT_EXTENDED = new HttpStatus(510, "Not Extended");
        self::$NETWORK_AUTHENTICATION_REQUIRED = new HttpStatus(511, "Network Authentication Required");

    }
}

HttpStatus::init();
