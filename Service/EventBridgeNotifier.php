<?php

namespace MageOS\EventBridge\Service;

use MageOS\EventBridge\Model\Config as EventBridgeConfig;
use MageOS\AsyncEvents\Service\AsyncEvent\NotifierInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\AsyncEvents\Helper\NotifierResult;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Aws\EventBridge\EventBridgeClient;
use Psr\Log\LoggerInterface;

/**
 * Class EventBridgeNotifier
 *
 * A notifier for relaying events into Amazon EventBridge.
 *
 */
class EventBridgeNotifier implements NotifierInterface
{
    /**
     * @var Json
     */
    private $json;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EventBridgeConfig
     */
    private $config;

    /**
     * @var EventBridgeClient|bool
     */
    private $eventBridgeClient;

    /**
     * @param Json $json
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger
     * @param EventBridgeConfig $config
     */
    public function __construct(
        Json $json,
        EncryptorInterface $encryptor,
        LoggerInterface $logger,
        EventBridgeConfig $config
    ) {
        $this->json = $json;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * {@inheritDoc}
     */
    public function notify(AsyncEventInterface $asyncEvent, array $data): NotifierResult
    {
        $notifierResult = new NotifierResult();
        $notifierResult->setSubscriptionId($asyncEvent->getSubscriptionId());
        $notifierResult->setAsyncEventData($data);

        try {
            // Ensure that the client is configured correctly before calling putEvents function
            $client = $this->getEventBridgeClient();
            if (!$client) {
                $notifierResult->setSuccess(false);
                $notifierResult->setResponseData(
                    $this->json->serialize(__('EventBridge connection is not configured.'))
                );
                return $notifierResult;
            }
            $result = $client->putEvents([
                 'Entries' => [
                      [
                           'Source' => $this->config->getEventBridgeSource(),
                           'Detail' => $this->json->serialize($data),
                           'DetailType' => $asyncEvent->getEventName(),
                           'Resources' => [],
                           'Time' => time(),
                           'EventBusName' => $asyncEvent->getRecipientUrl()
                      ]
                 ]
            ]);

            // Some event failures, which don't fail the entire request but do cause a failure of the
            // event submission result in failed results in the result->entries.
            // https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-eventbridge-2015-10-07.html#putevents
            if ( isset($result['FailedEntryCount']) && $result['FailedEntryCount'] > 0) {
                 $notifierResult->setSuccess(false);

                 // As we are only ever submitting one event at a time, assume that only one result
                 // can be returned.
                 $entry = $result['Entries'][0];
                 if ($entry !== null) {
                      $notifierResult->setResponseData(
                           $this->json->serialize($entry)
                      );
                 }

            } else {
                 $notifierResult->setSuccess(true);

                 $notifierResult->setResponseData(
                      $this->json->serialize($result)
                 );
            }
        } catch (\Exception $exception) {
            $this->logger->error($exception);

            $notifierResult->setSuccess(false);

            $notifierResult->setResponseData(
                $this->json->serialize($exception)
            );
        }

        return $notifierResult;
    }

    /**
     * Initialise and/or return the EventBridge client
     *
     * @return EventBridgeClient|bool
     */
    private function getEventBridgeClient()
    {
        if (!isset($this->eventBridgeClient)) {
            $region = $this->config->getAWSRegion();
            $key = $this->config->getAWSKeyId();
            $secret = $this->config->getAWSSecretKey();

            if ($region === null || $key === null || $secret === null) {
                $this->eventBridgeClient = false;
            } else {
                $this->eventBridgeClient = new EventBridgeClient(
                    [
                        'version' => '2015-10-07',
                        'region' => $region,
                        'credentials' => [
                            'key' => $key,
                            'secret' => $this->encryptor->decrypt($secret)
                        ]
                    ]
                );
            }
        }
        return $this->eventBridgeClient;
    }
}
