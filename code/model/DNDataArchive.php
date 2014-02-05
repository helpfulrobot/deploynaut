<?php
/**
 * Represents a file archive of database and/or assets extracted from
 * a specific Deploynaut environment.
 *
 * The model can also represent a request to upload a file later,
 * through offline processes like mailing a DVD. In order to associate
 * and authenticate those requests easily, an upload token is generated for every archive.
 * 
 * The "Environment" points to original source of this archive
 * (the one it was backed up from). In case this archive was created
 * from an offline process (see above), it denotes the target environment
 * which the file will eventually be restored to. View and download
 * permission on archives are based on environments, so this relationship is mandatory.
 *
 * The archive can have associations to {@link DNDataTransfer}:
 * - Zero transfers if a manual upload was requested, but not fulfilled yet
 * - One transfer with Direction=get for a backup from an environment
 * - One or more transfers with Direction=push for a restore to an environment
 *
 * The "Author" is either the person creating the archive through a "backup" operation,
 * the person uploading through a web form, or the person requesting a manual upload.
 * 
 * The "Mode" is what the "Author" said the file includes (either 'only assets', 'only 
 * database', or both). This is used in the ArchiveList.ss template.
 */
class DNDataArchive extends DataObject {

	private static $db = array(
		'UploadToken' => 'Varchar(8)',
		'Mode' => "Enum('all, assets, db', '')",
		'ArchiveFileHash' => 'Varchar(32)',
		"Mode" => "Enum('all, assets, db', '')",
		"IsBackup" => "Boolean"
	);

	private static $has_one = array(
		'Author' => 'Member',
		'Environment' => 'DNEnvironment',
		'ArchiveFile' => 'File'
	);

	private static $has_many = array(
		'DataTransfers' => 'DNDataTransfer',
	);

	private static $singular_name = 'Data Archive';

	private static $plural_name = 'Data Archives';

	private static $summary_fields = array(
		'Created' => 'Created',
		'Author.Title' => 'Author',
		'Environment.Project.Name' => 'Project',
		'Environment.Name' => 'Environment',
		'ArchiveFile.Name' => 'File',
	);

	private static $searchable_fields = array(
		'Environment.Project.Name' => array(
			'title' => 'Project',
		),
		'Environment.Name' => array(
			'title' => 'Environment',
		),
		'UploadToken' => array(
			'title' => 'Upload Token',
		),
		'Mode' => array(
			'title' => 'Mode',
		),
	);

	public function onBeforeWrite() {
		if(!$this->AuthorID) {
			$this->AuthorID = Member::currentUserID();
		}

		parent::onBeforeWrite();
	}

	public function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->removeByName('EnvironmentID');
		$fields->removeByName('ArchiveFile');
		$fields->addFieldsToTab(
			'Root.Main',
			array(
				new ReadonlyField('ProjectName', 'Project', $this->Environment()->Project()->Name),
				new ReadonlyField('EnvironmentName', 'Environment', $this->Environment()->Name),
				$linkField = new ReadonlyField(
					'DataArchive', 
					'Archive File', 
					sprintf(
						'<a href="%s">%s</a>',
						$this->ArchiveFile()->AbsoluteURL,
						$this->ArchiveFile()->Filename	
					)
				),
				new GridField(
					'DataTransfers', 
					'Transfers', 
					$this->DataTransfers()
				),
			)
		);
		$linkField->dontEscape = true;
		$fields = $fields->makeReadonly();

		return $fields;
	}

	public function getDefaultSearchContext() {
		$context = parent::getDefaultSearchContext();
		$context->getFields()->dataFieldByName('Mode')->setHasEmptyDefault(true);

		return $context;
	}

	/**
	 * Calculates and returns a human-readable size of this archive file. If the file exists, it will determine
	 * whether to display the output in bytes, kilobytes, megabytes, or gigabytes.
	 * 
	 * @return string The human-readable size of this archive file
	 */
	public function FileSize() {
		if($this->ArchiveFile()->exists()) {
			return $this->ArchiveFile()->getSize();
		} else {
			return "N/A";
		}
	}

	public function getModeNice() {
		if($this->Mode == 'all') {
			return 'database and assets';
		} else {
			return $this->Mode;
		}
	}

	/**
	 * Some archives don't have files attached to them yet,
	 * because a file has been posted offline and is waiting to be uploaded
	 * against this "archive placeholder".
	 * 
	 * @return boolean
	 */
	public function isPending() {
		return !($this->ArchiveFileID);
	}

	/**
	 * Inferred from both restore and backup permissions.
	 *
	 * @param Member|null $member The {@link Member} object to test against.
	 */
	public function canView($member = null) {
		return ($this->canRestore($member) || $this->canDownload($member));
	}

	/**
	 * Whether a {@link Member} can restore this archive to an environment.
	 *
	 * @param Member|null $member The {@link Member} object to test against.
	 * @return true if $member (or the currently logged in member if null) can upload this archive
	 */
	public function canRestore($member = null) {
		return $this->Environment()->canUploadArchive($member);
	}

	/**
	 * Whether a {@link Member} can download this archive to their PC.
	 *
	 * @param Member|null $member The {@link Member} object to test against.
	 * @return true if $member (or the currently logged in member if null) can download this archive
	 */
	public function canDownload($member = null) {
		return $this->Environment()->canDownloadArchive($member);
	}

	/**
	 * Returns a unique filename, including project/environment/timestamp details.
	 * @return string
	 */
	public function generateFilename(DNDataTransfer $dataTransfer) {
		$generator = new RandomGenerator();
		$sanitizeRegex = array('/\s+/', '/[^a-zA-Z0-9-_\.]/');
		$sanitizeReplace = array('/_/', '');
		$envName = strtolower(preg_replace($sanitizeRegex, $sanitizeReplace, $this->Environment()->Name));
		$projectName = strtolower(preg_replace(
			$sanitizeRegex,
			$sanitizeReplace,
			$this->Environment()->Project()->Name
		));

		return sprintf(
			'%s-%s-%s-%s-%s',
			$projectName,
			$envName,
			$dataTransfer->Mode,
			date('Ymd'),
			sha1($generator->generateEntropy())
		);
	}

	/**
	 * Returns a path unique to a specific transfer, including project/environment details.
	 * Does not create the path on the filesystem. Can be used to store files related to this transfer.
	 *
	 * @param DNDataTransfer
	 * @return String Absolute file path
	 */
	public function generateFilepath(DNDataTransfer $dataTransfer) {
		$filepath = null;
		$data = Injector::inst()->get('DNData');
		$transferDir = $data->getDataTransferDir();
		$sanitizeRegex = array('/\s+/', '/[^a-zA-Z0-9-_\.]/');
		$sanitizeReplace = array('/_/', '');
		$projectName = strtolower(preg_replace($sanitizeRegex, $sanitizeReplace, $this->Environment()->Project()->Name));
		$envName = strtolower(preg_replace($sanitizeRegex, $sanitizeReplace, $this->Environment()->Name));
		
		return sprintf('%s/%s/%s/transfer-%s/',
			$transferDir,
			$projectName,
			$envName,
			$dataTransfer->ID
		);	
		
	}

	public static function get_mode_map() {
		return array(
			'all' => 'Database and Assets',
			'db' => 'Database only',
			'assets' => 'Assets only',
		);
	}

	/**
	 * Returns a unique token to correlate an offline item (posted DVD)
	 * with a specific archive placeholder.
	 * 
	 * @return String
	 */
	public static function generate_upload_token($chars = 8) {
		$generator = new RandomGenerator();
		return strtoupper(substr($generator->randomToken(), 0, $chars));
	}

}
