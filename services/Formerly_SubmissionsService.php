<?php
namespace Craft;

class Formerly_SubmissionsService extends BaseApplicationComponent
{
	public function getSubmissionById($submissionId)
	{
		return craft()->elements->getElementById($submissionId, 'Formerly_Submission');
	}

	public function postSubmission(Formerly_SubmissionModel $submission)
	{
		$this->onBeforePost(new Event($this, array(
			'submission' => $submission
		)));


		if ($this->saveSubmission($submission))
		{
			//Find any multiline text fields and replace \n with break tags
			foreach ($submission->getForm()->getQuestions() as $question)
				if ($question->type == 'MultilineText') {
					$handle = $question['handle'];
					$answer = $submission[$handle];
					$answer = str_replace("\n", '<br />', $answer);
					$submission->getContent()->setAttributes(array(
						$handle => $answer
					));
					craft()->elements->saveElement($submission);
					$answer = $submission[$handle];
				}

			$submission = craft()->elements->getElementById($submission->id, 'Formerly_Submission');

			$this->sendSubmissionEmails($submission);

			$this->onPost(new Event($this, array(
				'submission' => $submission
			)));

			return true;
		}



		return false;
	}

	public function alreadySubmitted($email, $formId) {
		$alreadySubmitted = false;

		$criteria = craft()->elements->getCriteria('Formerly_Submission');
		$criteria-> search = $email;

		foreach($criteria->find() as $submission)
		{
			if ($submission->formId == $formId) {
				$alreadySubmitted = true;
			}
		}

		if ($alreadySubmitted) {
			return true;
		}

		return false;

	}

	public function saveSubmission(Formerly_SubmissionModel $submission)
	{
		$submissionRecord = new Formerly_SubmissionRecord();

		$submissionRecord->formId = $submission->formId;

		$submissionRecord->validate();
		$submission->addErrors($submissionRecord->getErrors());

		$this->onAfterValidate(new Event($this, array(
			'submission' => $submission
		)));


		if (!$submission->hasErrors())
		{


			//Check for honeypot
			if (craft()->config->exists(Formerly_ConfigSettings::SettingsGroupName) &&
				array_key_exists(Formerly_ConfigSettings::HoneyPotName, craft()->config->get(Formerly_ConfigSettings::SettingsGroupName))) {
				$honeyPotName =  craft()->config->get(Formerly_ConfigSettings::SettingsGroupName)[Formerly_ConfigSettings::HoneyPotName];
				if (array_key_exists($honeyPotName, $_REQUEST) && $_REQUEST[$honeyPotName] != null) {
					//ooh we have data in our honeypot!
					//don't flag an error just return back
					return false;
				}
			}

            foreach ($_REQUEST['questions'] as $key => $value) {
                if (strpos($key, 'email') > -1)
                    $email = $value;
            }

			$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;

			try {
				if (craft()->elements->saveElement($submission)) {

					$submissionRecord->id = $submission->id;

					$submissionRecord->save(false);

					if ($transaction !== null) {
						$transaction->commit();
					}

					return true;
				} else {
					return false;
				}
			} catch (\Exception $ex) {
				if ($transaction !== null) {
					$transaction->rollback();
				}
			}
		}

		return false;
	}

	public function deleteSubmissionById($submissionId)
	{
		if (!$submissionId)
		{
			return false;
		}

		$affectedRows = craft()->db->createCommand()->delete('formerly_submissions', array(
			'id' => $submissionId
		));

		return (bool) $affectedRows;
	}

	public function sendSubmissionEmails(Formerly_SubmissionModel $submission)
	{
		if (!$submission)
		{
			return false;
		}

		$sendEmail = true;
		$writeEmailToFilePath = '';

		//Check settings
		if (craft()->config->exists(Formerly_ConfigSettings::SettingsGroupName) &&
			array_key_exists(Formerly_ConfigSettings::SendEmails, craft()->config->get(Formerly_ConfigSettings::SettingsGroupName))) {
			$sendEmail = craft()->config->get(Formerly_ConfigSettings::SettingsGroupName)[Formerly_ConfigSettings::SendEmails];
		}

		if (craft()->config->exists(Formerly_ConfigSettings::SettingsGroupName) &&
			array_key_exists(Formerly_ConfigSettings::writeEmailBodyToFilePath, craft()->config->get(Formerly_ConfigSettings::SettingsGroupName))) {
			$writeEmailToFilePath = craft()->config->get(Formerly_ConfigSettings::SettingsGroupName)[Formerly_ConfigSettings::writeEmailBodyToFilePath];
		}

		$form = $submission->getForm();

		if ($form->emails !== null && ($sendEmail || strlen($writeEmailToFilePath) > 0))
		{
			foreach ($form->emails as $emailDef)
			{
				if (empty($emailDef['to'])) continue;

				$email = new EmailModel();
				$email->toEmail = $this->_renderSubmissionTemplate($emailDef['to'], $submission);
				$email->subject = !empty($emailDef['subject']) ? $this->_renderSubmissionTemplate($emailDef['subject'], $submission) : 'Website Enquiry';

				if (!empty($emailDef['from']))
				{
					$from = $this->_renderSubmissionTemplate($emailDef['from'], $submission);

					// https://regex101.com/r/yI0hL1/1
					preg_match('/^(.+)\<(.+)\>$/', $from, $matches);

					if (count($matches) >= 3)
					{
						// The provided from email is in the format Name <email>.
						$email->fromName  = trim($matches[1]);
						$email->fromEmail = trim($matches[2]);
					}
					else
					{
						// Note: If no from email is set, the default is the craft admin email address.
						$email->fromEmail = $from;
					}
				}

				if (!empty($emailDef['body']))
				{
					$email->body     = $this->_renderSubmissionTemplate($emailDef['body'], $submission);
					$email->htmlBody = $email->body;
				}
				else
				{
					$email->body     = $submission->getSummary();
					$email->htmlBody = $email->body;
				}

				if (!empty($email->body) && $sendEmail)
				{
					craft()->email->sendEmail($email);
				}

				if (strlen($writeEmailToFilePath) > 0) {
						$file = $writeEmailToFilePath . '/form-' . $form->id . '-submission-' . $submission->id . '.json';
						$jsonEmail = new \StdClass();
						$jsonEmail->toEmail = $email->toEmail;
						$jsonEmail->fromName = $email->fromName;
						$jsonEmail->fromEmail = $email->fromEmail;
						$jsonEmail->subject = $email->subject;
						$jsonEmail->body = $email->body;

						file_put_contents($file, json_encode($jsonEmail));
				}
			}
		}
	}

	private function _renderSubmissionTemplate($template, Formerly_SubmissionModel $submission)
	{
		$formHandle = $submission->getForm()->handle;

		$formattedTemplate = $template;

		//check that all the tags are valid before passing them to the template engine, otherwise it
		//crashes with an obscure error
		preg_match_all('/{(.+?)(\||})/', $template, $matches);

		$qs = $submission->getForm()->getQuestions();
		$tagsAllFound = true;
		foreach ($matches[1] as $a ){
			foreach ($qs as $q) {
				if (strstr($formHandle . '_' . $a, $q->handle)) {

					//if multiple result field do replace now

					//this is a valid twig field replace it with a temporary start and end tag
					//(because we want to replace all non matches later with something so twig doesn't try to replace the nonmatches)
					$formattedTemplate = str_replace("{" . $a . "}" , "@@@1" . $formHandle . '_' . $a . '1@@@', $formattedTemplate);
					$formattedTemplate = str_replace("{" . $a . "|" , "@@@2" . $formHandle . '_' . $a . '2@@@', $formattedTemplate);
					break;
				}
			}
		}

		//replace any stragglers
		preg_match_all('/{(.+?)(\||})/', $template, $matches);
		foreach ($matches[1] as $a ) {
			$formattedTemplate = str_replace("{" . $a . "}", "<<<" . $a . '>>>', $formattedTemplate);
		}

		//fix up actual matches
		$formattedTemplate = str_replace("@@@1" , "{", $formattedTemplate);
		$formattedTemplate = str_replace("1@@@" , "}", $formattedTemplate);
		$formattedTemplate = str_replace("@@@2" , "{", $formattedTemplate);
		$formattedTemplate = str_replace("2@@@" , "|", $formattedTemplate);

		$result = craft()->templates->renderObjectTemplate($formattedTemplate, $submission);


		//put unmatched handles back the way they were
		$result = str_replace('<<<', '{', $result);
		$result = str_replace(">>>" , "}" , $result);

		$result = str_replace("{id}", $submission->id, $result);

		$siteUrl = craft()->config->get("siteUrl");
		if (is_array($siteUrl) && count($siteUrl) > 0) $result = str_replace("{siteUrl}", $siteUrl[CRAFT_LOCALE], $result);
		else $result = str_replace("{siteUrl}", $siteUrl, $result);


		return $result;
	}

	public function onBeforePost(Event $event)
	{
		$this->raiseEvent('onBeforePost', $event);
	}

	public function onAfterValidate(Event $event)
	{
		$this->raiseEvent('onAfterValidate', $event);
	}


	public function onPost(Event $event)
	{
		$this->raiseEvent('onPost', $event);
	}
}
