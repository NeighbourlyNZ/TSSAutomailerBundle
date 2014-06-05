<?php

namespace TSS\AutomailerBundle\Library;

use TSS\AutomailerBundle\Entity\Automailer as Am;

class AutomailerSpool extends \Swift_ConfigurableSpool
{
    /**
     * The Entity Manager
     */
    private $_em;

    /**
     * Create a new AutomailerSpool.
     * @param  Doctrine\EntityManager $em
     * @throws Swift_IoException
     */
    public function __construct($em)
    {
        $this->_em = $em;
    }

    /**
     * Tests if this Spool mechanism has started.
     *
     * @return boolean
     */
    public function isStarted()
    {
        return true;
    }

    /**
     * Starts this Spool mechanism.
     */
    public function start()
    {
    }

    /**
     * Stops this Spool mechanism.
     */
    public function stop()
    {
    }

    /**
     * Queues a message.
     * @param  Swift_Mime_Message $message The message to store
     * @return boolean
     * @throws Swift_IoException
     */
    public function queueMessage(\Swift_Mime_Message $message)
    {
        $mail = new Am;
        $mail->setSubject($message->getSubject());
        $fromArray = $message->getFrom();
        $fromArrayKeys = array_keys($fromArray);
        $mail->setFromEmail($fromArrayKeys[0]);
        $mail->setFromName(isset($fromArray[$fromArrayKeys[0]])?$fromArray[$fromArrayKeys[0]] : $fromArrayKeys[0]);
        $toArray = $message->getTo();
        $toArrayKeys = array_keys($toArray);
        $mail->setToEmail($toArrayKeys[0]);
        $mail->setBody($message->getBody());
        $mail->setAltBody(strip_tags(preg_replace(
            array(
                '@<head[^>]*?>.*?</head>@siu',
                '@<style[^>]*?>.*?</style>@siu',
                '@<script[^>]*?.*?</script>@siu',
                '@<noscript[^>]*?.*?</noscript>@siu',
            ),
            "",
            $message->getBody())));
        $mail->setIsHtml(($message->getContentType()=='text/html') ? true : false);
        $mail->setSwiftMessage($message);

        $this->_em->persist($mail);
        $this->_em->flush();
    }

    /**
     * Execute a recovery if for anyreason a process is sending for too long
     */
    public function recover($timeout = 900)
    {
        return $this->_em->getRepository("TSSAutomailerBundle:Automailer")->recoverSending($timeout);
    }

    /**
     * Sends messages using the given transport instance.
     *
     * @param Swift_Transport $transport A transport instance
     * @param string[]        &$failedRecipients An array of failures by-reference
     *
     * @return int The number of sent emails
     */
    public function flushQueue(\Swift_Transport $transport, &$failedRecipients = null)
    {
        if (!$transport->isStarted()) {
            $transport->start();
        }

        $failedRecipients = (array) $failedRecipients;
        $count = 0;
        $time = time();

        $limit = !$this->getMessageLimit() ? 50 : $this->getMessageLimit();
        $perLimit = 50;
        $left = $limit;

        $this->_em->getConnection()->getConfiguration()->setSQLLogger(null);
        $automailerRepository = $this->_em->getRepository("TSSAutomailerBundle:Automailer");

        // Iterate over 50 at a time.
        // Otherwise the initial query gets too long.
        do {

            $left = $left - $perLimit;
            $mails = $automailerRepository->findNext($perLimit);

            foreach ($mails as $mail) {
                $mail->setIsSending(true);
                $mail->setStartedSendingAt(new \DateTime());
                $this->_em->persist($mail);
                $this->_em->flush();

                if ($transport->send($mail->getSwiftMessage(), $failedRecipients)) {
                    $count++;

                    $mail->setIsSending(false);
                    $mail->setIsSent(true);
                    $mail->setSentAt(new \DateTime());
                    $this->_em->persist($mail);
                } else {
                    $mail->setIsSending(false);
                    $mail->setIsFailed(true);
                    $this->_em->persist($mail);
                }

                $this->_em->flush();

                if ($this->getMessageLimit() && $count >= $this->getMessageLimit()) {
                    break(2);
                }

                if ($this->getTimeLimit() && (time() - $time) >= $this->getTimeLimit()) {
                    break(2);
                }
            }

            $this->_em->clear();
            gc_collect_cycles();

        } while ($left > 0);

        $this->_em->flush();

        return $count;
    }
}
