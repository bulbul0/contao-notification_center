<?php

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2011 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  terminal42 gmbh 2013
 * @license    LGPL
 */

namespace NotificationCenter\Gateway;

use NotificationCenter\Model\Language;
use NotificationCenter\Model\Message;


class Email extends Base implements GatewayInterface
{
    /**
     * SMTP settings cache
     * @var array
     */
    protected $arrSMTPCache = array();

    /**
     * Send email message
     * @param   Message
     * @param   array
     * @param   string
     * @return  bool
     */
    public function send(Message $objMessage, array $arrTokens, $strLanguage = '')
    {
        if ($strLanguage == '') {
            $strLanguage = $GLOBALS['TL_LANGUAGE'];
        }

        if (($objLanguage = Language::findByMessageAndLanguageOrFallback($objMessage, $strLanguage)) === null) {
            \System::log(sprintf('Could not find matching language or fallback for message ID "%s" and language "%s".', $objMessage->id, $strLanguage), __METHOD__, TL_ERROR);

            return false;
        }

        // Override SMTP settings if desired
        $this->overrideSMTPSettings();
        $objEmail           = new \Email();
        $this->resetSMTPSettings();

        // Set priority
        $objEmail->priority = $objMessage->email_priority;

        // Set optional sender name
        $strSenderName = $objLanguage->email_sender_name ? : $GLOBALS['TL_ADMIN_NAME'];
        if ($strSenderName != '') {
            $objEmail->fromName = $this->recursiveReplaceTokensAndTags($strSenderName, $arrTokens, static::NO_TAGS|static::NO_BREAKS);
        }

        // Set email sender address
        $strSenderAddress = $objLanguage->email_sender_address ? : $GLOBALS['TL_ADMIN_EMAIL'];
        $objEmail->from   = $this->recursiveReplaceTokensAndTags($strSenderAddress, $arrTokens, static::NO_TAGS|static::NO_BREAKS);

        // Set reply-to address
        if ($objLanguage->email_replyTo) {
            $objEmail->replyTo($this->recursiveReplaceTokensAndTags($objLanguage->email_replyTo, $arrTokens, static::NO_TAGS|static::NO_BREAKS));
        }

        // Set email subject
        $objEmail->subject = $this->recursiveReplaceTokensAndTags($objLanguage->email_subject, $arrTokens, static::NO_TAGS|static::NO_BREAKS);

        // Set email text content
        $strText        = $objLanguage->email_text;
        $strText        = $this->recursiveReplaceTokensAndTags($strText, $arrTokens, static::NO_TAGS);
        $objEmail->text = \Controller::convertRelativeUrls($strText, '', true);

        // Set optional email HTML content
        if ($objLanguage->email_mode == 'textAndHtml') {
            $objTemplate          = new \FrontendTemplate($objMessage->email_template);
            $objTemplate->body    = $objLanguage->email_html;
            $objTemplate->charset = $GLOBALS['TL_CONFIG']['characterSet'];

            // Prevent parseSimpleTokens from stripping important HTML tags
            $GLOBALS['TL_CONFIG']['allowedTags'] .= '<doctype><html><head><meta><style><body>';
            $strHtml = str_replace('<!DOCTYPE', '<DOCTYPE', $objTemplate->parse());
            $strHtml = $this->recursiveReplaceTokensAndTags($strHtml, $arrTokens);
            $strHtml = \Controller::convertRelativeUrls($strHtml, '', true);
            $strHtml = str_replace('<DOCTYPE', '<!DOCTYPE', $strHtml);

            // Parse template
            $objEmail->html     = $strHtml;
            $objEmail->imageDir = TL_ROOT . '/';
        }

        // Add all token attachments
        $arrTokenAttachments = $this->getTokenAttachments($objLanguage->attachment_tokens, $arrTokens);
        if (!empty($arrTokenAttachments)) {
            foreach ($arrTokenAttachments as $strFile) {
                $objEmail->attachFile($strFile);
            }
        }

        // Add static attachments
        $arrAttachments = deserialize($objLanguage->attachments);

        if (is_array($arrAttachments) && !empty($arrAttachments)) {
            $objFiles = \FilesModel::findMultipleByUuids($arrAttachments);
            while ($objFiles->next()) {
                $objEmail->attachFile(TL_ROOT . '/' . $objFiles->path);
            }
        }

        // Set CC recipients
        $arrCc = $this->compileRecipients($objLanguage->email_recipient_cc, $arrTokens);
        if (!empty($arrCc)) {
            $objEmail->sendCc($arrCc);
        }

        // Set BCC recipients
        $arrBcc = $this->compileRecipients($objLanguage->email_recipient_bcc, $arrTokens);
        if (!empty($arrBcc)) {
            $objEmail->sendBcc($arrBcc);
        }

        try {
            return $objEmail->sendTo($this->recursiveReplaceTokensAndTags($objLanguage->recipients, $arrTokens, static::NO_TAGS|static::NO_BREAKS));
        } catch (\Exception $e) {
            \System::log(sprintf('Could not send email for message ID %s: %s', $objMessage->id, $e->getMessage()), __METHOD__, TL_ERROR);
        }

        return false;
    }

    /**
     * Override SMTP settings
     */
    protected function overrideSMTPSettings()
    {
        if (!$this->objModel->email_overrideSmtp) {
            return;
        }

        $this->arrSMTPCache['useSMTP'] = $GLOBALS['TL_CONFIG']['useSMTP'];
        $GLOBALS['TL_CONFIG']['useSMTP'] = true;

        foreach (array('smtpHost', 'smtpUser', 'smtpPass', 'smtpEnc', 'smtpPort') as $strKey) {
            $this->arrSMTPCache[$strKey] = $GLOBALS['TL_CONFIG'][$strKey];
            $strEmailKey = 'email_' . $strKey;
            $GLOBALS['TL_CONFIG'][$strKey] = $this->objModel->{$strEmailKey};
        }
    }

    /**
     * Reset SMTP settings
     */
    protected function resetSMTPSettings()
    {
        if (!$this->objModel->email_overrideSmtp) {
            return;
        }

        foreach (array('useSMTP', 'smtpHost', 'smtpUser', 'smtpPass', 'smtpEnc', 'smtpPort') as $strKey) {
            $GLOBALS['TL_CONFIG'][$strKey] = $this->arrSMTPCache[$strKey];
        }
    }
}
