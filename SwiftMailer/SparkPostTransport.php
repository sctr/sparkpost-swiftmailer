<?php

/*
 * Copyright 2017 SCTR Services
 *
 * Distribution and reproduction are prohibited.
 *
 * @package     sparkpost-swiftmailer
 * @copyright   SCTR Services LLC 2017
 * @license     No License (Proprietary)
 */

namespace Sctr\SparkPostSwiftMailer;

use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use SparkPost\SparkPost;

class SparkPostTransport implements \Swift_Transport
{
    /**
     * @var \Swift_Events_EventDispatcher
     */
    protected $dispatcher;

    /** @var string|null */
    protected $apiKey;

    /** @var array|null */
    protected $resultApi;

    /** @var array|null */
    protected $fromEmail;

    /**
     * @param \Swift_Events_EventDispatcher $dispatcher
     */
    public function __construct(\Swift_Events_EventDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
        $this->apiKey     = null;
    }

    /**
     * @return bool|void
     */
    public function ping()
    {
        return true;
    }

    /**
     * Not used.
     */
    public function isStarted()
    {
        return false;
    }

    /**
     * Not used.
     */
    public function start()
    {
    }

    /**
     * Not used.
     */
    public function stop()
    {
    }

    /**
     * @param string $apiKey
     *
     * @return $this
     */
    public function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * @throws \Swift_TransportException
     *
     * @return \SparkPost\SparkPost
     */
    protected function createSparkPost()
    {
        if ($this->apiKey === null) {
            throw new \Swift_TransportException('Cannot create instance of \SparkPost\SparkPost while API key is NULL');
        }

        return new SparkPost(
            new GuzzleAdapter(new Client()),
            ['key' => $this->apiKey]
        );
    }

    /**
     * @param \Swift_Mime_SimpleMessage $message
     * @param null                      $failedRecipients
     *
     * @return int Number of messages sent
     */
    public function send(\Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->resultApi = null;
        if ($event = $this->dispatcher->createSendEvent($this, $message)) {
            $this->dispatcher->dispatchEvent($event, 'beforeSendPerformed');
            if ($event->bubbleCancelled()) {
                return 0;
            }
        }

        $sendCount = 0;

        $sparkPostMessage = $this->getSparkPostMessage($message);
        $sparkPost        = $this->createSparkPost();
        $promise          = $sparkPost->transmissions->post($sparkPostMessage);

        try {
            $response        = $promise->wait();
            $this->resultApi = $response->getBody();
        } catch (\Exception $e) {
            throw $e;
        }

        $sendCount = $this->resultApi['results']['total_accepted_recipients'];

        if ($this->resultApi['results']['total_rejected_recipients'] > 0) {
            $failedRecipients[] = $this->fromEmail;
        }

        if ($event) {
            if ($sendCount > 0) {
                $event->setResult(\Swift_Events_SendEvent::RESULT_SUCCESS);
            } else {
                $event->setResult(\Swift_Events_SendEvent::RESULT_FAILED);
            }

            $this->dispatcher->dispatchEvent($event, 'sendPerformed');
        }

        return $sendCount;
    }

    /**
     * @param \Swift_Events_EventListener $plugin
     */
    public function registerPlugin(\Swift_Events_EventListener $plugin)
    {
        $this->dispatcher->bindEventListener($plugin);
    }

    /**
     * @return array
     */
    protected function getSupportedContentTypes()
    {
        return [
            'text/plain',
            'text/html',
        ];
    }

    /**
     * @param string $contentType
     *
     * @return bool
     */
    protected function supportsContentType($contentType)
    {
        return in_array($contentType, $this->getSupportedContentTypes());
    }

    /**
     * @param Swift_Mime_Message $message
     *
     * @return string
     */
    protected function getMessagePrimaryContentType(\Swift_Mime_SimpleMessage $message)
    {
        $contentType = $message->getContentType();

        if ($this->supportsContentType($contentType)) {
            return $contentType;
        }

        // SwiftMailer hides the content type set in the constructor of Swift_Mime_Message as soon
        // as you add another part to the message. We need to access the protected property
        // _userContentType to get the original type.
        $messageRef = new \ReflectionClass($message);
        if ($messageRef->hasProperty('_userContentType')) {
            $propRef = $messageRef->getProperty('_userContentType');
            $propRef->setAccessible(true);
            $contentType = $propRef->getValue($message);
        }

        return $contentType;
    }

    /**
     * https://jsapi.apiary.io/apis/sparkpostapi/introduction/subaccounts-coming-to-an-api-near-you-in-april!.html.
     *
     * @param \Swift_Mime_Message $message
     *
     * @throws \Swift_SwiftException
     *
     * @return array SparkPost Send Message
     */
    public function getSparkPostMessage(\Swift_Mime_SimpleMessage $message)
    {
        $contentType      = $this->getMessagePrimaryContentType($message);
        $fromAddresses    = $message->getFrom();
        $this->fromEmail  = key($fromAddresses);

        $toAddresses      = $message->getTo();
        $ccAddresses      = $message->getCc() ? $message->getCc() : [];
        $bccAddresses     = $message->getBcc() ? $message->getBcc() : [];
        $replyToAddresses = $message->getReplyTo() ? $message->getReplyTo() : [];

        $recipients  = [];
        $cc          = [];
        $bcc         = [];
        $attachments = [];
        $headers     = [];
        $tags        = [];
        $options     = [];

        if ($message->getHeaders()->has('X-MC-Tags')) {
            /** @var \Swift_Mime_Headers_UnstructuredHeader $tagsHeader */
            $tagsHeader = $message->getHeaders()->get('X-MC-Tags');
            $tags       = explode(',', $tagsHeader->getValue());
        }

        foreach ($toAddresses as $toEmail => $toName) {
            $recipients[] = [
                'address' => [
                    'email' => $toEmail,
                    'name'  => $toName,
                ],
                'tags' => $tags,
            ];
        }
        $reply_to = null;
        foreach ($replyToAddresses as $replyToEmail => $replyToName) {
            if ($replyToName) {
                $reply_to= sprintf('%s <%s>', $replyToName, $replyToEmail);
            } else {
                $reply_to = $replyToEmail;
            }
        }

        foreach ($ccAddresses as $ccEmail => $ccName) {
            $cc[] = [
                'email' => $ccEmail,
                'name'  => $ccName,
            ];
        }

        foreach ($bccAddresses as $bccEmail => $bccName) {
            $bcc[] = [
                'email' => $bccEmail,
                'name'  => $bccName,
            ];
        }

        $bodyHtml = $bodyText = null;

        if ($contentType === 'text/plain') {
            $bodyText = $message->getBody();
        } elseif ($contentType === 'text/html') {
            $bodyHtml = $message->getBody();
        } else {
            $bodyHtml = $message->getBody();
        }

        foreach ($message->getChildren() as $child) {
            if ($child instanceof \Swift_Attachment) {
                $attachments[] = [
                    'type'    => $child->getContentType(),
                    'name'    => $child->getFilename(),
                    'data'    => base64_encode($child->getBody()),
                ];
            } elseif ($child instanceof \Swift_MimePart && $this->supportsContentType($child->getContentType())) {
                if ($child->getContentType() == 'text/html') {
                    $bodyHtml = $child->getBody();
                } elseif ($child->getContentType() == 'text/plain') {
                    $bodyText = $child->getBody();
                }
            }
        }

        if ($message->getHeaders()->has('List-Unsubscribe')) {
            $headers['List-Unsubscribe'] = $message->getHeaders()->get('List-Unsubscribe')->getValue();
        }

        if ($message->getHeaders()->has('X-MC-InlineCSS')) {
            $options['inline_css'] = !empty($message->getHeaders()->get('X-MC-InlineCSS')->getValue()) ? true : false;
        }

        if ($message->getHeaders()->has('X-MC-Transactional')) {
            $options['transactional'] = !empty($message->getHeaders()->get('X-MC-Transactional')->getValue()) ? true : false;
        }

        $sparkPostMessage = [
            'recipients' => $recipients,
            'tags'       => $tags,
            'content'    => [
                'from' => [
                    'name'  => $fromAddresses[$this->fromEmail],
                    'email' => $this->fromEmail,
                ],
                'subject' => $message->getSubject(),
                'html'    => $bodyHtml,
                'text'    => $bodyText,
            ],
        ];

        if (count($options) > 0) {
            $sparkPostMessage['options'] = $options;
        }
        if (!empty($reply_to)) {
            $sparkPostMessage['content']['reply_to'] = $reply_to;
        }
        if (!empty($cc)) {
            $sparkPostMessage['cc'] = $cc;
        }
        if (!empty($bcc)) {
            $sparkPostMessage['bcc'] = $bcc;
        }
        if (!empty($headers)) {
            $sparkPostMessage['content']['headers'] = $headers;
        }

        if (count($attachments) > 0) {
            $sparkPostMessage['content']['attachments'] = $attachments;
        }

        if ($message->getHeaders()->has('X-MC-CampainID')) {
            $sparkPostMessage['campaign_id'] = $message->getHeaders()->get('X-MC-CampainID')->getValue();
        }

        return $sparkPostMessage;
    }

    /**
     * @return null|array
     */
    public function getResultApi()
    {
        return $this->resultApi;
    }
}
