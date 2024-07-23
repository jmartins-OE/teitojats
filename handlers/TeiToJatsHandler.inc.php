<?php

import('classes.handler.Handler');


class TeiToJatsHandler extends Handler
{
	protected object $plugin;

	protected array $allowedMethods = ['convert'];

	function __construct()
	{

		parent::__construct();

		$this->plugin = PluginRegistry::getPlugin('generic', 'teitojatsplugin');
		$this->addRoleAssignment([ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT, ROLE_ID_SITE_ADMIN], $this->allowedMethods);

	}

	function authorize($request, &$args, $roleAssignments): bool
	{
		import('lib.pkp.classes.security.authorization.WorkflowStageAccessPolicy');
		$this->addPolicy(new WorkflowStageAccessPolicy($request, $args, $roleAssignments,
			'submissionId', (int)$request->getUserVar('stageId')));
		return parent::authorize($request, $args, $roleAssignments);
	}

	public function extractExecute($args, $request): JSONMessage
	{
		$action = new Extract($this->plugin, $request, $args);

		$action->readInputData();

		$action->process();

		return $request->redirectUrlJson($request->getDispatcher()->url($request, ROUTE_PAGE, null, 'workflow', 'access', null,
			array(
				'submissionId' => $request->getUserVar('submissionId'),
				'stageId' => $request->getUserVar('stageId')
			)
		));
	}

	public function convert($args, $request): JSONMessage
	{
		$fileId = (int)$request->getUserVar('fileId');
		$submissionFiles = Services::get('submissionFile')->getMany([
			'fileIds' => [$fileId],
		]);
		$submissionFile = $submissionFiles->current();


		import('lib.pkp.classes.file.PrivateFileManager');
		$fileManager = new PrivateFileManager();
		$submissionId = $submissionFile->getData('submissionId');
		$submission = Services::get('submission')->get($submissionId);

		//TODO move saxon to config.inc.php

		$filePath = $fileManager->getBasePath() . '/' . $submissionFile->getData('path');
		$pluginPath = Core::getBaseDir() . '/' . $this->plugin->getPluginPath();
		$newFile = tempnam(sys_get_temp_dir(), 'tei2jats');
		$teitojats = "cd $pluginPath && java -jar  $pluginPath/bin/saxon-he-10.6.jar $filePath $pluginPath/xslt/TEI-Commons_to_JATS-Publishing.xsl -o:$newFile";
		shell_exec($teitojats);
		$genreId = $submissionFile->getData('genreId');
		// Add new JATS XML file
		$submissionDir = Services::get('submissionFile')->getSubmissionDir($submission->getData('contextId'), $submissionId);
		$newFileId = Services::get('file')->add(
			$newFile,
			$submissionDir . DIRECTORY_SEPARATOR . uniqid() . '.xml'
		);

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$newSubmissionFile = $submissionFileDao->newDataObject();
		$newName = [];
		if (gettype($submissionFile->getData('name')) == 'array') {
			foreach ($submissionFile->getData('name') as $localeKey => $name) {
				$newName[$localeKey] = pathinfo($name)['filename'] . '-jats.xml';
			}
		} else {
			$newName[$submissionFile->getData('locale')] = pathinfo($submissionFile->getData('name'))['filename'] . '-jats.xml';
		}

		$newSubmissionFile->setAllData(
			[
				'fileId' => $newFileId,
				'assocType' => $submissionFile->getData('assocType'),
				'assocId' => $submissionFile->getData('assocId'),
				'fileStage' => $submissionFile->getData('fileStage'),
				'mimetype' => 'application/xml',
				'locale' => $submissionFile->getData('locale'),
				'genreId' => $genreId,
				'name' => $newName,
				'submissionId' => $submissionId,
			]
		);

		$newSubmissionFile = Services::get('submissionFile')->add($newSubmissionFile, $request);

		$json = new JSONMessage(true);
		return $json;
	}
}

