<?php

declare(strict_types=1);

namespace DleFilesystem\S3 {

    use DateTimeInterface;

    class S3Client
    {
        private string $accessKeyId;
        private string $accessKeySecret;
        private string $region;
        private string $signingRegion;
        private string $endpoint;
        private ?string $sessionToken;
        private bool $pathStyle;
        private int $connectTimeout;
        private int $timeout;
        private array $temporaryStreamFiles = [];

        public function __construct(array $config = [])
        {
            $this->accessKeyId = (string) ($config['accessKeyId'] ?? '');
            $this->accessKeySecret = (string) ($config['accessKeySecret'] ?? '');
            $this->region = trim((string) ($config['region'] ?? '')) ?: 'us-east-1';
            $this->signingRegion = trim((string) ($config['signingRegion'] ?? $config['signatureRegion'] ?? $config['authRegion'] ?? '')) ?: $this->region;
            $this->endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
            $this->sessionToken = isset($config['sessionToken']) && $config['sessionToken'] !== '' ? (string) $config['sessionToken'] : null;
            $this->pathStyle = isset($config['pathStyle'])
                ? (bool) $config['pathStyle']
                : (isset($config['pathStyleEndpoint']) ? (bool) $config['pathStyleEndpoint'] : $this->endpoint !== '');
            $this->connectTimeout = max(15, (int) ($config['connectTimeout'] ?? $config['connect_timeout'] ?? 15));
            $this->timeout = max(300, (int) ($config['timeout'] ?? $config['requestTimeout'] ?? $config['request_timeout'] ?? 300));
        }

        public function __destruct()
        {
            $this->clearTemporaryStreamFiles();
        }

        public function objectExists(array $arguments): bool
        {
            $result = $this->request('HEAD', (string) $arguments['Bucket'], (string) $arguments['Key'], [], [], null, [404]);

            return $result['status'] >= 200 && $result['status'] < 300;
        }

        public function putObject(array $arguments): array
        {
            return $this->request(
                'PUT',
                (string) $arguments['Bucket'],
                (string) $arguments['Key'],
                [],
                $this->buildHeaders($arguments, 'putObject'),
                $arguments['Body'] ?? ''
            );
        }

        public function upload(string $bucket, string $key, $body, array $arguments = []): array
        {
            $arguments['Bucket'] = $bucket;
            $arguments['Key'] = $key;
            $arguments['Body'] = $body;

            return $this->putObject($arguments);
        }

        public function getObject(array $arguments): array
        {
            return $this->request('GET', (string) $arguments['Bucket'], (string) $arguments['Key'], $arguments['Query'] ?? []);
        }

        public function getObjectStream(array $arguments)
        {
            list($stream, $tmpFile) = $this->createTemporaryStream('s3r');

            if ($stream === false) {
                throw new \RuntimeException('Unable to create temporary stream for S3 response');
            }

            try {
                $this->request('GET', (string) $arguments['Bucket'], (string) $arguments['Key'], $arguments['Query'] ?? [], [], null, [], $stream);
                rewind($stream);

                return $stream;
            } catch (\Throwable $exception) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw $exception;
            }
        }

        public function closeStream($stream): void
        {
            $this->closeTemporaryStream($stream, null);
        }

        public function headObject(array $arguments): array
        {
            return $this->request('HEAD', (string) $arguments['Bucket'], (string) $arguments['Key'], $arguments['Query'] ?? []);
        }

        public function deleteObject(array $arguments): array
        {
            return $this->request('DELETE', (string) $arguments['Bucket'], (string) $arguments['Key'], [], [], null, [404]);
        }

        public function deleteObjects(array $arguments): array
        {
            $xml = $this->buildDeleteObjectsXml(
                (array) (($arguments['Delete'] ?? [])['Objects'] ?? []),
                (bool) (($arguments['Delete'] ?? [])['Quiet'] ?? false)
            );

            $response = $this->request(
                'POST',
                (string) $arguments['Bucket'],
                '',
                ['delete' => ''],
                [
                    'Content-Type' => 'application/xml',
                    'Content-MD5' => base64_encode(md5($xml, true)),
                ],
                $xml
            );

            $errors = $this->parseDeleteObjectsErrors((string) ($response['body'] ?? ''));

            if ($errors !== []) {
                throw new \RuntimeException('S3 delete objects failed: ' . implode('; ', $errors));
            }

            return $response;
        }

        public function listObjectsV2(array $arguments): array
        {
            $query = [
                'list-type' => '2',
            ];

            foreach ([
                'Delimiter' => 'delimiter',
                'Prefix' => 'prefix',
                'ContinuationToken' => 'continuation-token',
                'MaxKeys' => 'max-keys',
            ] as $key => $queryKey) {
                if (isset($arguments[$key]) && $arguments[$key] !== null && $arguments[$key] !== '') {
                    $query[$queryKey] = (string) $arguments[$key];
                }
            }

            $response = $this->request('GET', (string) $arguments['Bucket'], '', $query);

            return $this->parseListObjectsV2((string) $response['body']);
        }

        public function copyObject(array $arguments): array
        {
            return $this->request(
                'PUT',
                (string) $arguments['Bucket'],
                (string) $arguments['Key'],
                [],
                $this->buildHeaders($arguments, 'copyObject'),
                ''
            );
        }

        public function putObjectAcl(array $arguments): array
        {
            return $this->request(
                'PUT',
                (string) $arguments['Bucket'],
                (string) $arguments['Key'],
                ['acl' => ''],
                $this->buildHeaders($arguments, 'putObjectAcl'),
                ''
            );
        }

        public function createMultipartUpload(array $arguments): array
        {
            return $this->request(
                'POST',
                (string) $arguments['Bucket'],
                (string) $arguments['Key'],
                ['uploads' => ''],
                $this->buildHeaders($arguments, 'createMultipartUpload'),
                ''
            );
        }

        public function uploadPart(array $arguments): array
        {
            return $this->request(
                'PUT',
                (string) $arguments['Bucket'],
                (string) $arguments['Key'],
                [
                    'partNumber' => (string) $arguments['PartNumber'],
                    'uploadId' => (string) $arguments['UploadId'],
                ],
                $this->buildHeaders($arguments, 'uploadPart'),
                $arguments['Body'] ?? ''
            );
        }

        public function completeMultipartUpload(array $arguments): array
        {
            $xml = $this->buildCompleteMultipartUploadXml((array) (($arguments['MultipartUpload'] ?? [])['Parts'] ?? []));
            $headers = $this->buildHeaders($arguments, 'completeMultipartUpload');
            $headers['Content-Type'] = 'application/xml';

            return $this->request(
                'POST',
                (string) $arguments['Bucket'],
                (string) $arguments['Key'],
                ['uploadId' => (string) $arguments['UploadId']],
                $headers,
                $xml
            );
        }

        public function abortMultipartUpload(array $arguments): array
        {
            return $this->request(
                'DELETE',
                (string) $arguments['Bucket'],
                (string) $arguments['Key'],
                ['uploadId' => (string) $arguments['UploadId']],
                [],
                null,
                [404]
            );
        }

        public function getObjectAcl(array $arguments): array
        {
            $response = $this->request('GET', (string) $arguments['Bucket'], (string) $arguments['Key'], ['acl' => '']);

            return $this->parseAcl((string) $response['body']);
        }

        public function getUrl(string $bucket, string $key): string
        {
            $parts = $this->buildUrlParts($bucket, $key, []);

            return $parts['url'];
        }

        public function presign(string $bucket, string $key, DateTimeInterface $expiresAt, array $query = []): string
        {
            $parts = $this->buildUrlParts($bucket, $key, $query);
            $host = $parts['host'];
            $canonicalUri = $parts['canonical_uri'];
            $amzDate = gmdate('Ymd\THis\Z');
            $date = substr($amzDate, 0, 8);
            $expires = max(1, min(604800, $expiresAt->getTimestamp() - time()));
            $credentialScope = $date . '/' . $this->signingRegion . '/s3/aws4_request';
            $signedHeaders = 'host';

            $queryParameters = $query + [
                'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
                'X-Amz-Credential' => $this->accessKeyId . '/' . $credentialScope,
                'X-Amz-Date' => $amzDate,
                'X-Amz-Expires' => (string) $expires,
                'X-Amz-SignedHeaders' => $signedHeaders,
            ];

            if ($this->sessionToken !== null) {
                $queryParameters['X-Amz-Security-Token'] = $this->sessionToken;
            }

            ksort($queryParameters);
            $canonicalQuery = $this->buildQueryString($queryParameters, true);
            $canonicalRequest = implode("\n", [
                'GET',
                $canonicalUri,
                $canonicalQuery,
                'host:' . $host,
                '',
                $signedHeaders,
                'UNSIGNED-PAYLOAD',
            ]);

            $stringToSign = implode("\n", [
                'AWS4-HMAC-SHA256',
                $amzDate,
                $credentialScope,
                hash('sha256', $canonicalRequest),
            ]);

            $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));
            $queryParameters['X-Amz-Signature'] = $signature;

            return $parts['base_url'] . '?' . $this->buildQueryString($queryParameters, false);
        }

        private function request(string $method, string $bucket, string $key = '', array $query = [], array $headers = [], $body = null, array $allowedStatuses = [], $responseStream = null): array
        {
            if (!function_exists('curl_init')) {
                throw new \RuntimeException('cURL extension is required for S3 adapter');
            }

            $payload = $this->normalizeBody($body);
            $payloadHash = $payload['hash'];
            $contentLength = $payload['length'];
            $parts = $this->buildUrlParts($bucket, $key, $query);
            $host = $parts['host'];
            $canonicalUri = $parts['canonical_uri'];
            $amzDate = gmdate('Ymd\THis\Z');
            $date = substr($amzDate, 0, 8);
            $credentialScope = $date . '/' . $this->signingRegion . '/s3/aws4_request';
            $baseHeaders = [
                'host' => $host,
                'x-amz-content-sha256' => $payloadHash,
                'x-amz-date' => $amzDate,
            ];

            if ($this->sessionToken !== null) {
                $baseHeaders['x-amz-security-token'] = $this->sessionToken;
            }

            foreach ($headers as $name => $value) {
                $baseHeaders[strtolower((string) $name)] = trim((string) $value);
            }

            if (!isset($baseHeaders['content-length']) && $contentLength >= 0 && in_array($method, ['PUT', 'POST'], true)) {
                $baseHeaders['content-length'] = (string) $contentLength;
            }

            ksort($baseHeaders);
            ksort($query);

            $canonicalHeaders = '';

            foreach ($baseHeaders as $name => $value) {
                $canonicalHeaders .= $name . ':' . preg_replace('#\s+#', ' ', $value) . "\n";
            }

            $signedHeaders = implode(';', array_keys($baseHeaders));
            $canonicalRequest = implode("\n", [
                $method,
                $canonicalUri,
                $this->buildQueryString($query, true),
                $canonicalHeaders,
                $signedHeaders,
                $payloadHash,
            ]);

            $stringToSign = implode("\n", [
                'AWS4-HMAC-SHA256',
                $amzDate,
                $credentialScope,
                hash('sha256', $canonicalRequest),
            ]);

            $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKeyId . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . hash_hmac('sha256', $stringToSign, $this->signingKey($date));
            $baseHeaders['authorization'] = $authorization;

            $curlHeaders = [];

            foreach ($baseHeaders as $name => $value) {
                $curlHeaders[] = $this->formatHeaderName($name) . ': ' . $value;
            }

            $responseHeaders = [];
            $ch = curl_init($parts['url']);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => !is_resource($responseStream),
                CURLOPT_HEADER => false,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$responseHeaders): int {
                    $length = strlen($headerLine);
                    $headerLine = trim($headerLine);

                    if ($headerLine === '' || strpos($headerLine, ':') === false) {
                        return $length;
                    }

                    list($name, $value) = explode(':', $headerLine, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);

                    return $length;
                },
            ]);

            if (is_resource($responseStream)) {
                rewind($responseStream);
                ftruncate($responseStream, 0);
                curl_setopt($ch, CURLOPT_FILE, $responseStream);
            }

            if ($method === 'HEAD') {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            } elseif (in_array($method, ['PUT', 'POST'], true)) {
                if (isset($payload['resource']) && is_resource($payload['resource'])) {
                    rewind($payload['resource']);
                    curl_setopt($ch, CURLOPT_UPLOAD, true);
                    curl_setopt($ch, CURLOPT_INFILE, $payload['resource']);
                    curl_setopt($ch, CURLOPT_INFILESIZE, $contentLength);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload['string'] ?? '');
                }
            }

            $bodyResponse = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if (isset($payload['close']) && is_resource($payload['close'])) {
                $this->closeTemporaryStream($payload['close'], $payload['tmp_file'] ?? null);
            }

            if ($bodyResponse === false || $error !== '') {
                throw new \RuntimeException('S3 request failed: ' . $error);
            }

            if (is_resource($responseStream)) {
                if ($status >= 400 && !in_array($status, $allowedStatuses, true)) {
                    rewind($responseStream);
                    $responseBody = stream_get_contents($responseStream);
                    throw new \RuntimeException('S3 request failed with status ' . $status . ': ' . (string) $responseBody);
                }

                rewind($responseStream);

                return [
                    'status' => $status,
                    'headers' => $responseHeaders,
                    'body' => $responseStream,
                ];
            }

            if ($status >= 400 && !in_array($status, $allowedStatuses, true)) {
                throw new \RuntimeException('S3 request failed with status ' . $status . ': ' . (string) $bodyResponse);
            }

            return [
                'status' => $status,
                'headers' => $responseHeaders,
                'body' => (string) $bodyResponse,
            ];
        }

        private function normalizeBody($body): array
        {
            if ($body === null) {
                return ['hash' => hash('sha256', ''), 'length' => 0, 'string' => ''];
            }

            if (is_resource($body)) {
                $close = null;
                $stream = $body;

                if (!($meta = stream_get_meta_data($stream)) || !($meta['seekable'] ?? false)) {
                    list($temp, $tmpFile) = $this->createTemporaryStream('s3q');

                    if ($temp === false) {
                        throw new \RuntimeException('Unable to create temporary stream for S3 request');
                    }

                    if (stream_copy_to_stream($stream, $temp) === false) {
                        $this->closeTemporaryStream($temp, $tmpFile);
                        throw new \RuntimeException('Unable to buffer S3 request stream');
                    }

                    if (!rewind($temp)) {
                        $this->closeTemporaryStream($temp, $tmpFile);
                        throw new \RuntimeException('Unable to rewind S3 request stream');
                    }

                    $stream = $temp;
                    $close = $temp;
                }

                $hashContext = hash_init('sha256');
                hash_update_stream($hashContext, $stream);
                $length = fstat($stream)['size'] ?? -1;
                rewind($stream);

                return [
                    'hash' => hash_final($hashContext),
                    'length' => $length,
                    'resource' => $stream,
                    'close' => $close,
                    'tmp_file' => isset($tmpFile) ? $tmpFile : null,
                ];
            }

            $string = (string) $body;

            return [
                'hash' => hash('sha256', $string),
                'length' => strlen($string),
                'string' => $string,
            ];
        }

        private function buildHeaders(array $arguments, string $operation = ''): array
        {
            $headers = [];

            foreach ([
                'ACL' => 'x-amz-acl',
                'ContentType' => 'content-type',
                'ContentDisposition' => 'content-disposition',
                'ContentEncoding' => 'content-encoding',
                'ContentLanguage' => 'content-language',
                'ContentMD5' => 'content-md5',
                'CacheControl' => 'cache-control',
                'Expires' => 'expires',
                'StorageClass' => 'x-amz-storage-class',
                'ServerSideEncryption' => 'x-amz-server-side-encryption',
                'CopySource' => 'x-amz-copy-source',
                'MetadataDirective' => 'x-amz-metadata-directive',
                'TaggingDirective' => 'x-amz-tagging-directive',
                'GrantFullControl' => 'x-amz-grant-full-control',
                'GrantRead' => 'x-amz-grant-read',
                'GrantReadACP' => 'x-amz-grant-read-acp',
                'GrantWriteACP' => 'x-amz-grant-write-acp',
                'CopySourceIfMatch' => 'x-amz-copy-source-if-match',
                'CopySourceIfNoneMatch' => 'x-amz-copy-source-if-none-match',
                'CopySourceIfModifiedSince' => 'x-amz-copy-source-if-modified-since',
                'CopySourceIfUnmodifiedSince' => 'x-amz-copy-source-if-unmodified-since',
                'SSECustomerAlgorithm' => 'x-amz-server-side-encryption-customer-algorithm',
                'SSECustomerKey' => 'x-amz-server-side-encryption-customer-key',
                'SSECustomerKeyMD5' => 'x-amz-server-side-encryption-customer-key-MD5',
                'SSEKMSKeyId' => 'x-amz-server-side-encryption-aws-kms-key-id',
                'SSEKMSEncryptionContext' => 'x-amz-server-side-encryption-context',
                'BucketKeyEnabled' => 'x-amz-server-side-encryption-bucket-key-enabled',
                'RequestPayer' => 'x-amz-request-payer',
                'ExpectedBucketOwner' => 'x-amz-expected-bucket-owner',
                'Tagging' => 'x-amz-tagging',
                'WebsiteRedirectLocation' => 'x-amz-website-redirect-location',
                'ObjectLockMode' => 'x-amz-object-lock-mode',
                'ObjectLockRetainUntilDate' => 'x-amz-object-lock-retain-until-date',
                'ObjectLockLegalHoldStatus' => 'x-amz-object-lock-legal-hold',
                'WriteOffsetBytes' => 'x-amz-write-offset-bytes',
                'IfMatch' => 'if-match',
                'IfNoneMatch' => 'if-none-match',
                'MpuObjectSize' => 'x-amz-mp-object-size',
                'ChecksumType' => 'x-amz-checksum-type',
                'ChecksumCRC32' => 'x-amz-checksum-crc32',
                'ChecksumCRC32C' => 'x-amz-checksum-crc32c',
                'ChecksumCRC64NVME' => 'x-amz-checksum-crc64nvme',
                'ChecksumSHA1' => 'x-amz-checksum-sha1',
                'ChecksumSHA256' => 'x-amz-checksum-sha256',
                'ChecksumMD5' => 'x-amz-checksum-md5',
                'ChecksumXXHASH64' => 'x-amz-checksum-xxhash64',
                'ChecksumXXHASH3' => 'x-amz-checksum-xxhash3',
                'ChecksumXXHASH128' => 'x-amz-checksum-xxhash128',
                'ChecksumSHA512' => 'x-amz-checksum-sha512',
            ] as $key => $headerName) {
                if (isset($arguments[$key]) && $arguments[$key] !== '') {
                    $headers[$headerName] = (string) $arguments[$key];
                }
            }

            if (isset($arguments['ChecksumAlgorithm']) && $arguments['ChecksumAlgorithm'] !== '') {
                $headers[in_array($operation, ['copyObject', 'createMultipartUpload'], true) ? 'x-amz-checksum-algorithm' : 'x-amz-sdk-checksum-algorithm'] = (string) $arguments['ChecksumAlgorithm'];
            }

            if (isset($arguments['Metadata']) && is_array($arguments['Metadata'])) {
                foreach ($arguments['Metadata'] as $key => $value) {
                    $headers['x-amz-meta-' . strtolower((string) $key)] = (string) $value;
                }
            }

            return $headers;
        }

        private function buildDeleteObjectsXml(array $objects, bool $quiet = false): string
        {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';

            foreach ($objects as $object) {
                $key = isset($object['Key']) ? (string) $object['Key'] : '';
                $xml .= '<Object><Key>' . htmlspecialchars($key, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Key></Object>';
            }

            if ($quiet) {
                $xml .= '<Quiet>true</Quiet>';
            }

            return $xml . '</Delete>';
        }

        private function buildCompleteMultipartUploadXml(array $parts): string
        {
            usort($parts, static function (array $left, array $right): int {
                return ((int) ($left['PartNumber'] ?? 0)) <=> ((int) ($right['PartNumber'] ?? 0));
            });

            $xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
            $checksumElements = [
                'ChecksumCRC32',
                'ChecksumCRC32C',
                'ChecksumCRC64NVME',
                'ChecksumSHA1',
                'ChecksumSHA256',
                'ChecksumMD5',
                'ChecksumXXHASH64',
                'ChecksumXXHASH3',
                'ChecksumXXHASH128',
                'ChecksumSHA512',
            ];

            foreach ($parts as $part) {
                $partNumber = (int) ($part['PartNumber'] ?? 0);
                $etag = (string) ($part['ETag'] ?? '');

                if ($partNumber < 1 || $etag === '') {
                    continue;
                }

                $xml .= '<Part>';

                foreach ($checksumElements as $element) {
                    if (isset($part[$element]) && $part[$element] !== '') {
                        $xml .= '<' . $element . '>' . htmlspecialchars((string) $part[$element], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $element . '>';
                    }
                }

                $xml .= '<ETag>' . htmlspecialchars($etag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</ETag><PartNumber>' . $partNumber . '</PartNumber></Part>';
            }

            return $xml . '</CompleteMultipartUpload>';
        }

        private function parseDeleteObjectsErrors(string $xml): array
        {
            if ($xml === '') {
                return [];
            }

            $document = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);

            if ($document === false || !isset($document->Error)) {
                return [];
            }

            $errors = [];

            foreach ($document->Error as $error) {
                $key = (string) ($error->Key ?? '');
                $code = (string) ($error->Code ?? 'Error');
                $message = (string) ($error->Message ?? '');
                $errors[] = trim($key . ': ' . $code . ($message !== '' ? ' - ' . $message : ''), ': ');
            }

            return $errors;
        }

        private function parseListObjectsV2(string $xml): array
        {
            $result = [
                'Contents' => [],
                'CommonPrefixes' => [],
                'IsTruncated' => false,
                'NextContinuationToken' => null,
            ];

            if ($xml === '') {
                return $result;
            }

            $document = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);

            if ($document === false) {
                return $result;
            }

            if (isset($document->Contents)) {
                foreach ($document->Contents as $item) {
                    $entry = [
                        'Key' => (string) $item->Key,
                        'LastModified' => (string) $item->LastModified,
                        'ETag' => trim((string) $item->ETag, '"'),
                        'Size' => (int) $item->Size,
                    ];

                    if (isset($item->ChecksumAlgorithm)) {
                        $entry['ChecksumAlgorithm'] = [];

                        foreach ($item->ChecksumAlgorithm as $algorithm) {
                            $entry['ChecksumAlgorithm'][] = (string) $algorithm;
                        }
                    }

                    if (isset($item->ChecksumType)) {
                        $entry['ChecksumType'] = (string) $item->ChecksumType;
                    }

                    $result['Contents'][] = $entry;
                }
            }

            if (isset($document->CommonPrefixes)) {
                foreach ($document->CommonPrefixes as $item) {
                    $result['CommonPrefixes'][] = [
                        'Prefix' => (string) $item->Prefix,
                    ];
                }
            }

            $result['IsTruncated'] = ((string) ($document->IsTruncated ?? 'false')) === 'true';
            $result['NextContinuationToken'] = isset($document->NextContinuationToken) ? (string) $document->NextContinuationToken : null;

            return $result;
        }

        private function parseAcl(string $xml): array
        {
            $visibility = 'private';

            if ($xml !== '') {
                $document = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);

                if ($document !== false && isset($document->AccessControlList->Grant)) {
                    foreach ($document->AccessControlList->Grant as $grant) {
                        $grantee = $grant->Grantee;
                        $uri = isset($grantee->URI) ? (string) $grantee->URI : '';
                        $permission = strtoupper((string) ($grant->Permission ?? ''));

                        if ($uri === 'http://acs.amazonaws.com/groups/global/AllUsers' && in_array($permission, ['READ', 'FULL_CONTROL'], true)) {
                            $visibility = 'public';
                            break;
                        }
                    }
                }
            }

            return ['visibility' => $visibility];
        }

        private function buildUrlParts(string $bucket, string $key, array $query): array
        {
            $bucket = trim($bucket);
            $key = ltrim($key, '/');
            $queryString = $this->buildQueryString($query, false);

            if ($this->endpoint !== '') {
                $endpoint = parse_url($this->endpoint);
                $scheme = (string) ($endpoint['scheme'] ?? 'https');
                $host = (string) ($endpoint['host'] ?? '');
                $port = isset($endpoint['port']) ? ':' . (int) $endpoint['port'] : '';
                $basePath = isset($endpoint['path']) ? rtrim((string) $endpoint['path'], '/') : '';
                $usePathStyle = $this->pathStyle || !$this->supportsVirtualHostedStyle($bucket) || ($scheme === 'https' && strpos($bucket, '.') !== false);

                if ($usePathStyle) {
                    $path = $basePath . '/' . rawurlencode($bucket);

                    if ($key !== '') {
                        $path .= '/' . $this->encodeKey($key);
                    }
                } else {
                    $host = $bucket . '.' . $host;
                    $path = $basePath;

                    if ($key !== '') {
                        $path .= '/' . $this->encodeKey($key);
                    } elseif ($path === '') {
                        $path = '/';
                    }
                }

                $baseUrl = $scheme . '://' . $host . $port . ($path === '' ? '/' : $path);
                $canonicalUri = $path === '' ? '/' : $path;
            } else {
                $scheme = 'https';
                $host = $this->pathStyle || strpos($bucket, '.') !== false
                    ? 's3.' . $this->region . '.amazonaws.com'
                    : $bucket . '.s3.' . $this->region . '.amazonaws.com';
                $path = $this->pathStyle || strpos($bucket, '.') !== false ? '/' . rawurlencode($bucket) : '';

                if ($key !== '') {
                    $path .= '/' . $this->encodeKey($key);
                } elseif ($path === '') {
                    $path = '/';
                }

                $baseUrl = $scheme . '://' . $host . $path;
                $canonicalUri = $path;
            }

            return [
                'host' => $host,
                'base_url' => $baseUrl,
                'url' => $queryString !== '' ? $baseUrl . '?' . $queryString : $baseUrl,
                'canonical_uri' => $canonicalUri === '' ? '/' : $canonicalUri,
            ];
        }

        private function encodeKey(string $key): string
        {
            return str_replace('%2F', '/', rawurlencode($key));
        }

        private function buildQueryString(array $query, bool $canonical): string
        {
            if ($query === []) {
                return '';
            }

            ksort($query);
            $parts = [];

            foreach ($query as $name => $value) {
                $encodedName = rawurlencode((string) $name);
                $encodedValue = rawurlencode((string) $value);
                $parts[] = $encodedName . '=' . $encodedValue;
            }

            return implode('&', $parts);
        }

        private function signingKey(string $date): string
        {
            $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->accessKeySecret, true);
            $kRegion = hash_hmac('sha256', $this->signingRegion, $kDate, true);
            $kService = hash_hmac('sha256', 's3', $kRegion, true);

            return hash_hmac('sha256', 'aws4_request', $kService, true);
        }

        private function supportsVirtualHostedStyle(string $bucket): bool
        {
            if ($bucket === '' || strlen($bucket) < 3 || strlen($bucket) > 63) {
                return false;
            }

            if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $bucket)) {
                return false;
            }

            if (strpos($bucket, '..') !== false || preg_match('/(?:^|[.])-|-(?:$|[.])/', $bucket)) {
                return false;
            }

            if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $bucket)) {
                return false;
            }

            return true;
        }

        private function formatHeaderName(string $name): string
        {
            $parts = explode('-', $name);
            $parts = array_map(static function (string $part): string {
                return $part === 'md5' ? 'MD5' : ucfirst($part);
            }, $parts);

            return implode('-', $parts);
        }

        private function createTemporaryStream(string $prefix): array
        {
            $stream = @fopen('php://temp', 'w+b');

            if ($stream !== false) {
                return [$stream, null];
            }

            $tmpFile = $this->createTemporaryCacheFile($prefix);

            if ($tmpFile === false) {
                return [false, null];
            }

            $stream = @fopen($tmpFile, 'w+b');

            if ($stream === false) {
                @unlink($tmpFile);
                return [false, null];
            }

            $this->temporaryStreamFiles[(int) $stream] = $tmpFile;

            return [$stream, $tmpFile];
        }

        private function closeTemporaryStream($stream, ?string $tmpFile): void
        {
            $streamKey = is_resource($stream) ? (int) $stream : null;

            if ($tmpFile === null && $streamKey !== null && isset($this->temporaryStreamFiles[$streamKey])) {
                $tmpFile = $this->temporaryStreamFiles[$streamKey];
            }

            if (is_resource($stream)) {
                @fclose($stream);
            }

            if ($tmpFile !== null && $tmpFile !== '') {
                @unlink($tmpFile);
                $this->forgetTemporaryStreamFile($tmpFile, $streamKey);
            }
        }

        private function forgetTemporaryStreamFile(string $tmpFile, ?int $streamKey = null): void
        {
            if ($streamKey !== null && isset($this->temporaryStreamFiles[$streamKey])) {
                unset($this->temporaryStreamFiles[$streamKey]);
                return;
            }

            $key = array_search($tmpFile, $this->temporaryStreamFiles, true);

            if ($key !== false) {
                unset($this->temporaryStreamFiles[$key]);
            }
        }

        private function clearTemporaryStreamFiles(): void
        {
            foreach ($this->temporaryStreamFiles as $file) {
                if (is_string($file) && $file !== '' && is_file($file)) {
                    @unlink($file);
                }
            }

            $this->temporaryStreamFiles = [];
        }

        private function temporaryCacheDirectory(): string
        {
            return rtrim(str_replace('\\', '/', \ENGINE_DIR), '/') . '/cache/system';
        }

        private function createTemporaryCacheFile(string $prefix)
        {
            $directory = $this->temporaryCacheDirectory();

            if (!is_dir($directory) || !is_writable($directory)) {
                return false;
            }

            $tmpFile = @tempnam($directory . '/', $prefix);

            if ($tmpFile === false) {
                return false;
            }

            $tmpFile = str_replace('\\', '/', $tmpFile);

            if (strpos($tmpFile, $directory . '/') !== 0) {
                @unlink($tmpFile);
                return false;
            }

            return $tmpFile;
        }
    }
}

namespace DleFilesystem\Adapters {

    use DleFilesystem\S3\S3Client;
    use DateTimeInterface;
    use DleFilesystem\ChecksumProvider;
    use DleFilesystem\Config;
    use DleFilesystem\DirectoryAttributes;
    use DleFilesystem\FileAttributes;
    use DleFilesystem\FilesystemAdapter;
    use DleFilesystem\PathPrefixer;
    use DleFilesystem\StorageAttributes;
    use DleFilesystem\FilesystemException;
    use DleFilesystem\UrlGeneration\PublicUrlGenerator;
    use DleFilesystem\UrlGeneration\TemporaryUrlGenerator;
    use DleFilesystem\Visibility;
    use DleFilesystem\MimeTypeDetection\FinfoMimeTypeDetector;
    use DleFilesystem\MimeTypeDetection\MimeTypeDetector;
    use Throwable;

    class S3VisibilityConverter
    {
        private string $defaultVisibility;

        public function __construct(string $defaultVisibility = Visibility::PRIVATE)
        {
            $this->defaultVisibility = in_array($defaultVisibility, [Visibility::PUBLIC, Visibility::PRIVATE], true) ? $defaultVisibility : Visibility::PRIVATE;
        }

        public function visibilityToAcl(string $visibility): string
        {
            return $visibility === Visibility::PUBLIC ? 'public-read' : 'private';
        }

        public function aclToVisibility(array $metadata): string
        {
            return ($metadata['visibility'] ?? Visibility::PRIVATE) === Visibility::PUBLIC ? Visibility::PUBLIC : Visibility::PRIVATE;
        }

        public function defaultForDirectories(): string
        {
            return $this->defaultVisibility;
        }
    }

    class S3Adapter implements FilesystemAdapter, PublicUrlGenerator, ChecksumProvider, TemporaryUrlGenerator
    {
        private const MAX_DELETE_OBJECTS = 1000;
        private const MAX_MULTIPART_UPLOAD_PARTS = 10000;
        private const MIN_MULTIPART_UPLOAD_PART_SIZE = 5242880;
        private const MIME_TYPE_DETECTION_SAMPLE_SIZE = 65536;

        private S3Client $client;
        private string $bucket;
        private PathPrefixer $prefixer;
        private S3VisibilityConverter $visibility;
        private MimeTypeDetector $mimeTypeDetector;
        private array $forwardedOptions;
        private array $temporaryStreamFiles = [];

        public static function create(array $clientOptions, string $bucket, string $prefix = '', string $visibility = Visibility::PRIVATE, ?MimeTypeDetector $mimeTypeDetector = null, array $forwardedOptions = []): self
        {
            return new self(
                new S3Client($clientOptions),
                $bucket,
                $prefix,
                new S3VisibilityConverter($visibility),
                $mimeTypeDetector,
                $forwardedOptions
            );
        }

        public function __construct(S3Client $client, string $bucket, string $prefix = '', ?S3VisibilityConverter $visibility = null, ?MimeTypeDetector $mimeTypeDetector = null, array $forwardedOptions = [], array $metadataFields = [])
        {
            $this->client = $client;
            $this->bucket = $bucket;
            $this->prefixer = new PathPrefixer($prefix);
            $this->visibility = $visibility ?? new S3VisibilityConverter();
            $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
            $this->forwardedOptions = $forwardedOptions;
        }

        public function __destruct()
        {
            $this->clearTemporaryStreamFiles();
        }

        public function fileExists(string $path): bool
        {
            try {
                return $this->client->objectExists([
                    'Bucket' => $this->bucket,
                    'Key' => $this->prefixer->prefixPath($path),
                ]);
            } catch (Throwable $exception) {
                throw FilesystemException::forLocation($path, $exception);
            }
        }

        public function directoryExists(string $path): bool
        {
            try {
                $listing = $this->client->listObjectsV2([
                    'Bucket' => $this->bucket,
                    'Prefix' => $this->prefixer->prefixDirectoryPath($path),
                    'MaxKeys' => 1,
                    'Delimiter' => '/',
                ]);

                return !empty($listing['Contents']) || !empty($listing['CommonPrefixes']);
            } catch (Throwable $exception) {
                throw FilesystemException::forLocation($path, $exception);
            }
        }

        public function write(string $path, string $contents, Config $config): void
        {
            $this->upload($path, $contents, $config);
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            $this->upload($path, $contents, $config);
        }

        public function read(string $path): string
        {
            try {
                return (string) $this->client->getObject([
                    'Bucket' => $this->bucket,
                    'Key' => $this->prefixer->prefixPath($path),
                ])['body'];
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocation($path, $exception->getMessage(), $exception);
            }
        }

        public function readStream(string $path)
        {
            try {
                return $this->client->getObjectStream([
                    'Bucket' => $this->bucket,
                    'Key' => $this->prefixer->prefixPath($path),
                ]);
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocation($path, $exception->getMessage(), $exception);
            }
        }

        public function delete(string $path): void
        {
            try {
                $this->client->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $this->prefixer->prefixPath($path),
                ]);
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($path, $exception->getMessage(), $exception);
            }
        }

        public function deleteDirectory(string $path): void
        {
            $path = trim($path, '/');
            $objects = [];

            if ($path === '') {
                throw FilesystemException::atLocation($path, 'refusing to delete root directory');
            }

            try {
                foreach ($this->iterateListing($path, true) as $item) {
                    if ($item instanceof FileAttributes) {
                        $objects[] = ['Key' => $this->prefixer->prefixPath($item->path())];
                    } elseif ($item instanceof DirectoryAttributes) {
                        if ($item->path() === $path) {
                            continue;
                        }

                        $objects[] = ['Key' => $this->prefixer->prefixDirectoryPath($item->path())];
                    } else {
                        continue;
                    }

                    if (count($objects) === self::MAX_DELETE_OBJECTS) {
                        $this->client->deleteObjects([
                            'Bucket' => $this->bucket,
                            'Delete' => ['Objects' => $objects, 'Quiet' => true],
                        ]);

                        $objects = [];
                    }
                }

                $directoryKey = $this->prefixer->prefixDirectoryPath($path);

                if ($directoryKey !== '/') {
                    $objects[] = ['Key' => rtrim($directoryKey, '/') . '/'];
                }

                if ($objects !== []) {
                    $this->client->deleteObjects([
                        'Bucket' => $this->bucket,
                        'Delete' => ['Objects' => $objects, 'Quiet' => true],
                    ]);
                }
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($path, $exception->getMessage(), $exception);
            }
        }

        public function createDirectory(string $path, Config $config): void
        {
            $path = trim($path, '/');

            if ($path === '') {
                return;
            }

            $visibility = (string) $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $this->visibility->defaultForDirectories());
            $config = $config->withDefaults([Config::OPTION_VISIBILITY => $visibility]);
            $options = $this->createOptionsFromConfig($config);

            if (!isset($options['ACL']) && $visibility === Visibility::PUBLIC) {
                $options['ACL'] = $this->visibility->visibilityToAcl(Visibility::PUBLIC);
            }

            if (($options['ContentType'] ?? '') === '') {
                $options['ContentType'] = 'application/x-directory';
            }

            try {
                $this->client->upload($this->bucket, $this->prefixer->prefixDirectoryPath($path), '', $options);
            } catch (Throwable $exception) {
                throw FilesystemException::dueToFailure($path, $exception);
            }
        }

        public function setVisibility(string $path, string $visibility): void
        {
            try {
                $this->client->putObjectAcl([
                    'Bucket' => $this->bucket,
                    'Key' => $this->prefixer->prefixPath($path),
                    'ACL' => $this->visibility->visibilityToAcl($visibility),
                ]);
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($path, $exception->getMessage(), $exception);
            }
        }

        public function visibility(string $path): FileAttributes
        {
            try {
                $metadata = $this->client->getObjectAcl([
                    'Bucket' => $this->bucket,
                    'Key' => $this->prefixer->prefixPath($path),
                ]);

                return new FileAttributes($path, null, $this->visibility->aclToVisibility($metadata));
            } catch (Throwable $exception) {
                throw FilesystemException::visibility($path, $exception->getMessage(), $exception);
            }
        }

        public function mimeType(string $path): FileAttributes
        {
            $attributes = $this->fetchFileMetadata($path, StorageAttributes::ATTRIBUTE_MIME_TYPE);

            if ($attributes->mimeType() === null) {
                throw FilesystemException::mimeType($path);
            }

            return $attributes;
        }

        public function lastModified(string $path): FileAttributes
        {
            $attributes = $this->fetchFileMetadata($path, StorageAttributes::ATTRIBUTE_LAST_MODIFIED);

            if ($attributes->lastModified() === null) {
                throw FilesystemException::lastModified($path);
            }

            return $attributes;
        }

        public function fileSize(string $path): FileAttributes
        {
            $attributes = $this->fetchFileMetadata($path, StorageAttributes::ATTRIBUTE_FILE_SIZE);

            if ($attributes->fileSize() === null) {
                throw FilesystemException::fileSize($path);
            }

            return $attributes;
        }

        public function listContents(string $path, bool $deep): iterable
        {
            try {
                yield from $this->iterateListing($path, $deep);
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($path, $deep, $exception);
            }
        }

        public function move(string $source, string $destination, Config $config): void
        {
            if ($source === $destination) {
                return;
            }

            try {
                $this->copy($source, $destination, $config);
                $this->delete($source);
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocationTo($source, $destination, $exception);
            }
        }

        public function copy(string $source, string $destination, Config $config): void
        {
            if ($source === $destination) {
                return;
            }

            try {
                $visibility = $config->get(Config::OPTION_VISIBILITY);

                if ($visibility === null && $config->get(Config::OPTION_RETAIN_VISIBILITY, true)) {
                    $visibility = $this->visibility($source)->visibility();
                }

                $options = $this->createOptionsFromConfig($config);

                if (!isset($options['ACL']) && $visibility === Visibility::PUBLIC) {
                    $options['ACL'] = $this->visibility->visibilityToAcl(Visibility::PUBLIC);
                }

                $this->client->copyObject($options + [
                    'Bucket' => $this->bucket,
                    'Key' => $this->prefixer->prefixPath($destination),
                    'CopySource' => $this->bucket . '/' . str_replace('%2F', '/', rawurlencode($this->prefixer->prefixPath($source))),
                ]);
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocationTo($source, $destination, $exception);
            }
        }

        public function publicUrl(string $path, Config $config): string
        {
            try {
                return $this->client->getUrl($this->bucket, $this->prefixer->prefixPath($path));
            } catch (Throwable $exception) {
                throw FilesystemException::dueToError($path, $exception);
            }
        }

        public function checksum(string $path, Config $config): string
        {
            $algorithm = strtolower((string) $config->get('checksum_algo', 'etag'));

            if ($algorithm === 'etag') {
                $attributes = $this->fetchFileMetadata($path, 'checksum');
                $etag = $attributes->extraMetadata()['ETag'] ?? null;

                if (!is_string($etag) || $etag === '') {
                    throw FilesystemException::forPath($path, 'ETag is not available');
                }

                return trim($etag, '"');
            }

            if (!in_array($algorithm, hash_algos(), true)) {
                throw FilesystemException::forPath($path, 'hash algorithm is not supported');
            }

            $stream = $this->readStream($path);
            $context = hash_init($algorithm);

            try {
                $result = hash_update_stream($context, $stream);
            } finally {
                $this->client->closeStream($stream);
            }

            if ($result === false) {
                throw FilesystemException::forPath($path, 'failed to read stream while calculating checksum');
            }

            return hash_final($context);
        }

        public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
        {
            try {
                return $this->client->presign($this->bucket, $this->prefixer->prefixPath($path), $expiresAt, (array) $config->get('get_object_options', []));
            } catch (Throwable $exception) {
                throw FilesystemException::dueToError($path, $exception);
            }
        }

        private function upload(string $path, $body, Config $config): void
        {
            $key = $this->prefixer->prefixPath($path);
            $prepared = $this->prepareUploadBody($body);
            $uploadBody = $prepared['body'];
            $options = $this->createOptionsFromConfig($config);
            $visibility = $config->get(Config::OPTION_VISIBILITY);

            if (!isset($options['ACL']) && $visibility === Visibility::PUBLIC) {
                $options['ACL'] = $this->visibility->visibilityToAcl(Visibility::PUBLIC);
            }

            if (($options['ContentType'] ?? '') === '') {
                $options['ContentType'] = $this->detectUploadMimeType($key, $uploadBody) ?? 'application/octet-stream';
            }

            try {
                if ($this->shouldUseMultipartUpload($uploadBody, $config)) {
                    $this->uploadMultipart($key, $uploadBody, $options, $config);
                } else {
                    $this->client->upload($this->bucket, $key, $uploadBody, $options);
                }
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($path, $exception->getMessage(), $exception);
            } finally {
                if (isset($prepared['close']) && is_resource($prepared['close'])) {
                    $this->closeTemporaryStream($prepared['close'], $prepared['tmp_file'] ?? null);
                }
            }
        }

        private function prepareUploadBody($body): array
        {
            if (!is_resource($body)) {
                return ['body' => (string) $body, 'close' => null];
            }

            $meta = stream_get_meta_data($body);

            if (($meta['seekable'] ?? false)) {
                if (@ftell($body) !== 0) {
                    rewind($body);
                }

                return ['body' => $body, 'close' => null];
            }

            list($stream, $tmpFile) = $this->createTemporaryStream('s3u');

            if ($stream === false) {
                throw new \RuntimeException('Unable to create temporary stream for upload');
            }

            if (stream_copy_to_stream($body, $stream) === false) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw new \RuntimeException('Unable to buffer upload stream');
            }

            if (!rewind($stream)) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw new \RuntimeException('Unable to rewind upload stream');
            }

            return ['body' => $stream, 'close' => $stream, 'tmp_file' => $tmpFile];
        }

        private function detectUploadMimeType(string $key, $body): string
        {
            if (!is_resource($body)) {
                return $this->mimeTypeDetector->detectMimeType($key, (string) $body) ?? 'application/octet-stream';
            }

            $position = ftell($body);
            $sample = stream_get_contents($body, self::MIME_TYPE_DETECTION_SAMPLE_SIZE);

            if ($sample === false) {
                throw new \RuntimeException('Unable to read upload stream for MIME detection');
            }

            if ($position !== false && (stream_get_meta_data($body)['seekable'] ?? false)) {
                fseek($body, $position);
            }

            return $this->mimeTypeDetector->detectMimeType($key, (string) $sample) ?? 'application/octet-stream';
        }

        private function shouldUseMultipartUpload($body, Config $config): bool
        {
            if ((bool) $config->get('disable_multipart_upload', false)) {
                return false;
            }

            $threshold = max(self::MIN_MULTIPART_UPLOAD_PART_SIZE, (int) $config->get('multipart_upload_threshold', 16777216));
            $size = $this->detectBodySize($body);

            return $size !== null && $size >= $threshold;
        }

        private function detectBodySize($body): ?int
        {
            if (is_string($body)) {
                return strlen($body);
            }

            if (!is_resource($body)) {
                return null;
            }

            $stat = fstat($body);

            if (isset($stat['size']) && $stat['size'] >= 0) {
                return (int) $stat['size'];
            }

            return null;
        }

        private function uploadMultipart(string $key, $body, array $options, Config $config): void
        {
            $bodySize = $this->detectBodySize($body);
            $partSize = max(self::MIN_MULTIPART_UPLOAD_PART_SIZE, (int) $config->get('multipart_upload_part_size', 8388608));
            $partSize = $bodySize === null ? $partSize : max($partSize, (int) ceil($bodySize / self::MAX_MULTIPART_UPLOAD_PARTS));
            $uploadId = null;
            $parts = [];
            $source = $body;
            $closeSource = null;
            $closeSourceFile = null;

            if (!is_resource($source)) {
                list($source, $closeSourceFile) = $this->createTemporaryStreamFromContents((string) $body, 's3m');

                if ($source === false) {
                    throw new \RuntimeException('Unable to create temporary stream for multipart source');
                }

                $closeSource = $source;
            }

            try {
                $response = $this->client->createMultipartUpload(array_merge($options, [
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                ]));

                $bodyXml = (string) ($response['body'] ?? '');
                $document = @simplexml_load_string($bodyXml, 'SimpleXMLElement', LIBXML_NONET);
                $uploadId = $document !== false && isset($document->UploadId) ? (string) $document->UploadId : '';

                if ($uploadId === '') {
                    throw new \RuntimeException('Multipart upload did not return UploadId');
                }

                $partNumber = 1;

                while (!feof($source)) {
                    list($partStream, $partTmpFile) = $this->createTemporaryStream('s3p');

                    if ($partStream === false) {
                        throw new \RuntimeException('Unable to create temporary stream for multipart upload');
                    }

                    try {
                        $written = stream_copy_to_stream($source, $partStream, $partSize);

                        if ($written === false) {
                            throw new \RuntimeException('Failed to read stream part for multipart upload');
                        }

                        if ($written === 0) {
                            $this->closeTemporaryStream($partStream, $partTmpFile);
                            break;
                        }

                        if (!rewind($partStream)) {
                            throw new \RuntimeException('Unable to rewind multipart upload stream');
                        }

                        if ($partNumber > self::MAX_MULTIPART_UPLOAD_PARTS) {
                            throw new \RuntimeException('Multipart upload exceeds S3 part limit');
                        }

                        $partResponse = $this->client->uploadPart([
                            'Bucket' => $this->bucket,
                            'Key' => $key,
                            'UploadId' => $uploadId,
                            'PartNumber' => $partNumber,
                            'Body' => $partStream,
                        ] + $this->multipartUploadPartOptions($options));

                        $etag = (string) (($partResponse['headers']['etag'] ?? ''));

                        if ($etag === '') {
                            throw new \RuntimeException('Multipart upload part did not return ETag');
                        }

                        $part = [
                            'PartNumber' => $partNumber,
                            'ETag' => $etag,
                        ];

                        foreach ($this->multipartChecksumHeaders() as $header => $field) {
                            if (isset($partResponse['headers'][$header]) && $partResponse['headers'][$header] !== '') {
                                $part[$field] = (string) $partResponse['headers'][$header];
                            }
                        }

                        $parts[] = $part;
                        $partNumber++;
                    } finally {
                        if (is_resource($partStream)) {
                            $this->closeTemporaryStream($partStream, $partTmpFile);
                        }
                    }
                }

                if ($parts === []) {
                    throw new \RuntimeException('Multipart upload contains no parts');
                }

                $this->client->completeMultipartUpload([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                    'MultipartUpload' => ['Parts' => $parts],
                ] + $this->multipartCompleteOptions($options, $bodySize));
            } catch (Throwable $exception) {
                if (is_string($uploadId) && $uploadId !== '') {
                    try {
                        $this->client->abortMultipartUpload([
                            'Bucket' => $this->bucket,
                            'Key' => $key,
                            'UploadId' => $uploadId,
                        ]);
                    } catch (Throwable $abortException) {
                    }
                }

                throw $exception;
            } finally {
                if (is_resource($closeSource)) {
                    $this->closeTemporaryStream($closeSource, $closeSourceFile);
                }
            }
        }

        private function multipartUploadPartOptions(array $options): array
        {
            $partOptions = [];

            foreach ([
                'SSECustomerAlgorithm',
                'SSECustomerKey',
                'SSECustomerKeyMD5',
                'RequestPayer',
                'ExpectedBucketOwner',
            ] as $option) {
                if (isset($options[$option]) && $options[$option] !== '') {
                    $partOptions[$option] = $options[$option];
                }
            }

            return $partOptions;
        }

        private function multipartCompleteOptions(array $options, ?int $bodySize): array
        {
            $completeOptions = [];

            foreach ([
                'ChecksumType',
                'ChecksumCRC32',
                'ChecksumCRC32C',
                'ChecksumCRC64NVME',
                'ChecksumSHA1',
                'ChecksumSHA256',
                'ChecksumMD5',
                'ChecksumXXHASH64',
                'ChecksumXXHASH3',
                'ChecksumXXHASH128',
                'ChecksumSHA512',
                'SSECustomerAlgorithm',
                'SSECustomerKey',
                'SSECustomerKeyMD5',
                'RequestPayer',
                'ExpectedBucketOwner',
                'IfMatch',
                'IfNoneMatch',
                'MpuObjectSize',
            ] as $option) {
                if (isset($options[$option]) && $options[$option] !== '') {
                    $completeOptions[$option] = $options[$option];
                }
            }

            if ($bodySize !== null && !isset($completeOptions['MpuObjectSize']) && (isset($completeOptions['ChecksumCRC32']) || isset($completeOptions['ChecksumCRC32C']) || isset($completeOptions['ChecksumCRC64NVME']))) {
                $completeOptions['MpuObjectSize'] = $bodySize;
            }

            return $completeOptions;
        }

        private function multipartChecksumHeaders(): array
        {
            return [
                'x-amz-checksum-crc32' => 'ChecksumCRC32',
                'x-amz-checksum-crc32c' => 'ChecksumCRC32C',
                'x-amz-checksum-crc64nvme' => 'ChecksumCRC64NVME',
                'x-amz-checksum-sha1' => 'ChecksumSHA1',
                'x-amz-checksum-sha256' => 'ChecksumSHA256',
                'x-amz-checksum-md5' => 'ChecksumMD5',
                'x-amz-checksum-xxhash64' => 'ChecksumXXHASH64',
                'x-amz-checksum-xxhash3' => 'ChecksumXXHASH3',
                'x-amz-checksum-xxhash128' => 'ChecksumXXHASH128',
                'x-amz-checksum-sha512' => 'ChecksumSHA512',
            ];
        }

        private function createTemporaryStream(string $prefix): array
        {
            $stream = @fopen('php://temp', 'w+b');

            if ($stream !== false) {
                return [$stream, null];
            }

            return $this->createTemporaryFileStream($prefix);
        }

        private function createTemporaryStreamFromContents(string $contents, string $prefix): array
        {
            $stream = @fopen('php://temp', 'w+b');

            if ($stream !== false) {
                if ($this->writeTemporaryStreamContents($stream, $contents)) {
                    return [$stream, null];
                }

                @fclose($stream);
            }

            list($stream, $tmpFile) = $this->createTemporaryFileStream($prefix);

            if ($stream === false) {
                return [false, null];
            }

            if (!$this->writeTemporaryStreamContents($stream, $contents)) {
                $this->closeTemporaryStream($stream, $tmpFile);
                return [false, null];
            }

            return [$stream, $tmpFile];
        }

        private function writeTemporaryStreamContents($stream, string $contents): bool
        {
            $length = strlen($contents);

            for ($offset = 0; $offset < $length;) {
                $written = @fwrite($stream, substr($contents, $offset, 1048576));

                if ($written === false || $written === 0) {
                    return false;
                }

                $offset += $written;
            }

            return @rewind($stream);
        }

        private function createTemporaryFileStream(string $prefix): array
        {
            $tmpFile = $this->createTemporaryCacheFile($prefix);

            if ($tmpFile === false) {
                return [false, null];
            }

            $stream = @fopen($tmpFile, 'w+b');

            if ($stream === false) {
                @unlink($tmpFile);
                return [false, null];
            }

            $this->temporaryStreamFiles[(int) $stream] = $tmpFile;

            return [$stream, $tmpFile];
        }

        private function closeTemporaryStream($stream, ?string $tmpFile): void
        {
            $streamKey = is_resource($stream) ? (int) $stream : null;

            if ($tmpFile === null && $streamKey !== null && isset($this->temporaryStreamFiles[$streamKey])) {
                $tmpFile = $this->temporaryStreamFiles[$streamKey];
            }

            if (is_resource($stream)) {
                @fclose($stream);
            }

            if ($tmpFile !== null && $tmpFile !== '') {
                @unlink($tmpFile);
                $this->forgetTemporaryStreamFile($tmpFile, $streamKey);
            }
        }

        private function forgetTemporaryStreamFile(string $tmpFile, ?int $streamKey = null): void
        {
            if ($streamKey !== null && isset($this->temporaryStreamFiles[$streamKey])) {
                unset($this->temporaryStreamFiles[$streamKey]);
                return;
            }

            $key = array_search($tmpFile, $this->temporaryStreamFiles, true);

            if ($key !== false) {
                unset($this->temporaryStreamFiles[$key]);
            }
        }

        private function clearTemporaryStreamFiles(): void
        {
            foreach ($this->temporaryStreamFiles as $file) {
                if (is_string($file) && $file !== '' && is_file($file)) {
                    @unlink($file);
                }
            }

            $this->temporaryStreamFiles = [];
        }

        private function temporaryCacheDirectory(): string
        {
            return rtrim(str_replace('\\', '/', \ENGINE_DIR), '/') . '/cache/system';
        }

        private function createTemporaryCacheFile(string $prefix)
        {
            $directory = $this->temporaryCacheDirectory();

            if (!is_dir($directory) || !is_writable($directory)) {
                return false;
            }

            $tmpFile = @tempnam($directory . '/', $prefix);

            if ($tmpFile === false) {
                return false;
            }

            $tmpFile = str_replace('\\', '/', $tmpFile);

            if (strpos($tmpFile, $directory . '/') !== 0) {
                @unlink($tmpFile);
                return false;
            }

            return $tmpFile;
        }

        private function fetchFileMetadata(string $path, string $type): FileAttributes
        {
            try {
                $result = $this->client->headObject([
                    'Bucket' => $this->bucket,
                    'Key' => $this->prefixer->prefixPath($path),
                ]);

                $headers = $result['headers'] ?? [];
                $lastModified = isset($headers['last-modified']) ? strtotime((string) $headers['last-modified']) : null;
                $extraMetadata = isset($headers['etag']) ? ['ETag' => trim((string) $headers['etag'], '"')] : [];

                foreach ($this->multipartChecksumHeaders() + ['x-amz-checksum-type' => 'ChecksumType'] as $header => $field) {
                    if (isset($headers[$header]) && $headers[$header] !== '') {
                        $extraMetadata[$field] = (string) $headers[$header];
                    }
                }

                return new FileAttributes(
                    $path,
                    isset($headers['content-length']) ? (int) $headers['content-length'] : null,
                    null,
                    $lastModified !== false ? $lastModified : null,
                    isset($headers['content-type']) ? (string) $headers['content-type'] : null,
                    $extraMetadata
                );
            } catch (Throwable $exception) {
                throw FilesystemException::create($path, $type, $exception->getMessage(), $exception);
            }
        }

        private function iterateListing(string $path, bool $deep): \Generator
        {
            $prefix = trim($this->prefixer->prefixPath($path), '/');
            $prefix = $prefix === '' ? '' : $prefix . '/';
            $continuationToken = null;

            do {
                $listing = $this->client->listObjectsV2([
                    'Bucket' => $this->bucket,
                    'Prefix' => $prefix,
                    'Delimiter' => $deep ? null : '/',
                    'ContinuationToken' => $continuationToken,
                ]);

                foreach ($listing['CommonPrefixes'] as $item) {
                    $itemPath = rtrim($this->prefixer->stripPrefix((string) $item['Prefix']), '/');

                    if ($itemPath !== '' && $itemPath !== trim($path, '/')) {
                        yield new DirectoryAttributes($itemPath);
                    }
                }

                foreach ($listing['Contents'] as $item) {
                    $itemPath = $this->prefixer->stripPrefix((string) $item['Key']);

                    if ($itemPath === trim($path, '/')) {
                        continue;
                    }

                    if (substr($itemPath, -1) === '/') {
                        yield new DirectoryAttributes(rtrim($itemPath, '/'));
                        continue;
                    }

                    $extraMetadata = isset($item['ETag']) ? ['ETag' => (string) $item['ETag']] : [];

                    foreach (['ChecksumAlgorithm', 'ChecksumType'] as $field) {
                        if (isset($item[$field])) {
                            $extraMetadata[$field] = $item[$field];
                        }
                    }

                    yield new FileAttributes(
                        $itemPath,
                        isset($item['Size']) ? (int) $item['Size'] : null,
                        null,
                        isset($item['LastModified']) ? strtotime((string) $item['LastModified']) : null,
                        null,
                        $extraMetadata
                    );
                }

                $continuationToken = $listing['NextContinuationToken'] ?? null;
            } while (!empty($listing['IsTruncated']) && $continuationToken);
        }

        private function createOptionsFromConfig(Config $config): array
        {
            $options = [];
            $supported = $this->forwardedOptions !== [] ? $this->forwardedOptions : [
                'ContentDisposition',
                'ContentEncoding',
                'ContentLanguage',
                'ContentMD5',
                'ContentType',
                'CacheControl',
                'Expires',
                'Metadata',
                'ACL',
                'GrantFullControl',
                'GrantRead',
                'GrantReadACP',
                'GrantWriteACP',
                'MetadataDirective',
                'TaggingDirective',
                'StorageClass',
                'ServerSideEncryption',
                'SSECustomerAlgorithm',
                'SSECustomerKey',
                'SSECustomerKeyMD5',
                'SSEKMSKeyId',
                'SSEKMSEncryptionContext',
                'BucketKeyEnabled',
                'RequestPayer',
                'ExpectedBucketOwner',
                'Tagging',
                'WebsiteRedirectLocation',
                'ObjectLockMode',
                'ObjectLockRetainUntilDate',
                'ObjectLockLegalHoldStatus',
                'WriteOffsetBytes',
                'IfMatch',
                'IfNoneMatch',
                'CopySourceIfMatch',
                'CopySourceIfNoneMatch',
                'CopySourceIfModifiedSince',
                'CopySourceIfUnmodifiedSince',
                'MpuObjectSize',
                'ChecksumAlgorithm',
                'ChecksumType',
                'ChecksumCRC32',
                'ChecksumCRC32C',
                'ChecksumCRC64NVME',
                'ChecksumSHA1',
                'ChecksumSHA256',
                'ChecksumMD5',
                'ChecksumXXHASH64',
                'ChecksumXXHASH3',
                'ChecksumXXHASH128',
                'ChecksumSHA512',
            ];

            foreach ($supported as $option) {
                $value = $config->get($option);

                if ($value !== null && $value !== '') {
                    $options[$option] = $value;
                }
            }

            return $options;
        }
    }
}
