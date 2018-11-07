<?php

namespace Oro\Bundle\ApiBundle\Tests\Functional;

use Oro\Bundle\ApiBundle\Request\RequestType;
use Symfony\Component\HttpFoundation\Response;

/**
 * The base class for plain REST API functional tests.
 */
abstract class RestPlainApiTestCase extends RestApiTestCase
{
    protected const JSON_CONTENT_TYPE = 'application/json';

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->initClient();
        parent::setUp();
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequestType()
    {
        return new RequestType([RequestType::REST]);
    }

    /**
     * {@inheritdoc}
     */
    protected function request($method, $uri, array $parameters = [], array $server = [], $content = null)
    {
        if (!array_key_exists('HTTP_X-WSSE', $server)) {
            $server = array_replace($server, $this->getWsseAuthHeader());
        } elseif (!$server['HTTP_X-WSSE']) {
            unset($server['HTTP_X-WSSE']);
        }

        $this->client->request(
            $method,
            $uri,
            $parameters,
            [],
            $server,
            $content
        );

        return $this->client->getResponse();
    }

    /**
     * Asserts the response content contains the the given data.
     *
     * @param array|string $expectedContent The file name or full file path to YAML template file or array
     * @param Response     $response
     * @param object|null  $entity          If not null, object will set as entity reference
     */
    protected function assertResponseContains($expectedContent, Response $response, $entity = null)
    {
        if ($entity) {
            $this->getReferenceRepository()->addReference('entity', $entity);
        }

        $content = self::jsonToArray($response->getContent());
        $expectedContent = self::processTemplateData($this->loadResponseData($expectedContent));

        self::assertArrayContains($expectedContent, $content);
    }

    /**
     * Asserts the response content contains the the given validation error.
     *
     * @param array    $expectedError
     * @param Response $response
     */
    protected function assertResponseValidationError($expectedError, Response $response)
    {
        $this->assertResponseValidationErrors([$expectedError], $response);
    }

    /**
     * Asserts the response content contains the the given validation errors.
     *
     * @param array    $expectedErrors
     * @param Response $response
     */
    protected function assertResponseValidationErrors($expectedErrors, Response $response)
    {
        static::assertResponseStatusCodeEquals($response, Response::HTTP_BAD_REQUEST);

        $content = self::jsonToArray($response->getContent());
        try {
            $this->assertResponseContains($expectedErrors, $response);
            self::assertCount(
                count($expectedErrors),
                $content,
                'Unexpected number of validation errors'
            );
        } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
            throw new \PHPUnit\Framework\ExpectationFailedException(
                sprintf(
                    "%s\nResponse:\n%s",
                    $e->getMessage(),
                    json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                ),
                $e->getComparisonFailure()
            );
        }
    }
}
