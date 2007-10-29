<?php
/*
	Docvert 3.3 - Copyright (C) 2005-2006-2007
	by Matthew Cruickshank and the smart people in the CREDITS file.
	"One day I'll release them from that file."
	
	Licenced for use under the GPL version 3. See the LICENCE file.

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

include_once('process/interface-to-implement.php');
include_once('xslt.php');
include_once('shell-command.php');
include_once('xml.php');
include_once('config.php');
set_error_handler('phpErrorHandler');
date_default_timezone_set('UTC');

function processConversion($files, $converter, $pipeline, $autoPipeline, $afterConversion, $setupOpenOfficeOrg, $outputZip, $justShowPreviewDirectory=null)
	{
	
	ensureClientType();
	if(thereWasAFileGiven($files, $pipeline) || $justShowPreviewDirectory)
		{
		$returnZipPath = null;
		$previewDirectory = null;
		if(!$justShowPreviewDirectory)
			{
			$previewDirectory = getTemporaryDirectoryInsideDirectory( dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR.'writable', 'preview');
			$temporaryDirectory = getTemporaryDirectory();
			$pipelineToRunOnDocuments = substringAfter($pipeline, ':');
			foreach($files as $file)
				{
				if($file['size'] != 0)
					{
					$documentPath = moveUploadToConversionDirectory($file, $temporaryDirectory);
					$oasisOpenDocumentPath = '';
					if(!isAnOasisOpenDocument($file))
						{
						$oasisOpenDocumentPath = makeOasisOpenDocument($documentPath, $converter);
						silentlyUnlink($documentPath);
						}
					else
						{
						$oasisOpenDocumentPath = $documentPath;
						}
					if($pipelineToRunOnDocuments != "none")
						{
						extractUsefulOasisOpenDocumentFiles($oasisOpenDocumentPath);
						silentlyUnlink($oasisOpenDocumentPath);
						$oasisOpenDocumentContentPath = dirname($oasisOpenDocumentPath).DIRECTORY_SEPARATOR.'docvert-content.xml';
						applyPipeline($oasisOpenDocumentContentPath, $pipelineToRunOnDocuments, $autoPipeline, $previewDirectory);
						silentlyUnlink($oasisOpenDocumentContentPath);
						}
					}
				}
			$returnZipPath = zipAndDeleteTemporaryFiles($temporaryDirectory);
			if($afterConversion == 'preview')
				{
				include_once('core/webpage.php');
				$themes = new Themes;
				$returnZipPath = $themes->unzipConversionResults($returnZipPath, $previewDirectory);
				}
			}
		else
			{
			$previewDirectory = 'writable'.DIRECTORY_SEPARATOR.$justShowPreviewDirectory;
			$zipsInPreviewDirectory = glob($previewDirectory.DIRECTORY_SEPARATOR.'*.zip');
			if(count($zipsInPreviewDirectory) != 1)
				{
				webServiceError('Docvert internal error: ZIP file was not rebuilt. I found '.count($zipsInPreviewDirectory).' ZIP files in "'.$previewDirectory.'" '.implode());
				}
			$returnZipPath = $zipsInPreviewDirectory[0];
			}

		if($afterConversion == 'preview')
			{
			include_once('core/webpage.php');
			$themes = new Themes;		
			$themes->previewConversionResults($returnZipPath, $previewDirectory);
			}
		elseif($afterConversion == 'saveZip')
			{
			if(DOCVERT_CLIENT_TYPE == 'command line')
				{
				moveFile($returnZipPath, $outputZip);
				print 'Ok! File saved to '.$outputZip."\n";
				deleteDirectoryRecursively($temporaryDirectory);
				die();
				}
			else
				{
				webServiceError('Cannot run afterconversion=<b>saveZip</b> from non "command line" clients, sorry!');
				}
			}
		elseif($afterConversion == 'downloadZip')
			{
			//TODO: different versions of IE want different "content disposition" header syntaxes
			//perhaps we could detect versions of IE and serve up what they need? Will need quite a bit of research.
			header('Content-Type: application/x-zip-compressed');
			header('Content-disposition: attachment; filename='.basename($returnZipPath));
			$zipContents = file_get_contents($returnZipPath);
			print $zipContents;
			flush();
			silentlyUnlink($returnZipPath);
			deleteDirectoryRecursively($temporaryDirectory);
			die();
			}
		else
			{
			webServiceError('afterConversion was "'.revealXml($afterConversion).'"');
			}
		}
	elseif($setupOpenOfficeOrg)
		{
		setupOpenOfficeOrg();
		}
	else
		{
		/* TODO: so there's been no upload and what do we do?
		 * I suppose an http status code would be appropriate.
		 *     http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html
	 	* The 4xx series are for user error, which seems appropriate.
		 * ...but none of them seem quite appropriate for a casual
		 * "you didn't give me files" response.
		 * They talk about malformed requests.
	 	*
		 * So I'm doing a "400 Bad Request" in the meantime.
		*/
		webServiceError('<h1>No files uploaded</h1><p>This web service takes HTTP POSTs of binary MSWord, OpenDocument, and other word processing files but none were given. If you did give a file for upload then check local file permissions to see whether your browser could upload the file.</p>', '400 Bad Request');
		}
	}


/**
 * checks for valid file upload
 * @return boolean
 */
function ensureClientType()
	{
	switch(DOCVERT_CLIENT_TYPE)
		{
		case 'web':
		case 'command line':
			break;
		default:
			webServiceError('clientType must be either "web" or "command line"');
		}
	}


/**
 * checks for valid file upload
 * @return boolean
 */
function thereWasAFileGiven($files, $pipeline)
	{
	$validUpload = false;
	foreach($files as $file)
		{
		if($file['size'] != 0)
			{
			$validUpload = true;
			}
		}
	if($validUpload == true && $pipeline == false)
		{
		webServiceError('A form field of \'pipeline\' is required.');
		}
	return $validUpload;
	}

/**
 * ensure that a directory is made
 */
function ensureMakeDirectory($makeInsideDirectory, $directoryName)
	{
	if (substr($makeInsideDirectory, -1) != DIRECTORY_SEPARATOR) $makeInsideDirectory .= DIRECTORY_SEPARATOR;
	$directoryToMake = $makeInsideDirectory.$directoryName;
	$variation = 0;
	do
		{
		$directoryToMake = $makeInsideDirectory.$directoryName;
		$variation++;
		if($variation > 1)
			{
			$directoryToMake .= $variation;
			}
		if($variation >= 10)
			{
			webServiceError('Problem creating directory at '.$directoryToMake.' after '.$variation.' attempts.');
			}
		}
	while (@!mkdir($directoryToMake, 0777));
	return $directoryToMake;
	}

/**
 * moves uploaded file to the tmp conversion dir
 */
function moveUploadToConversionDirectory($file, $temporaryDirectory)
	{
	$documentPathInfo = pathinfo($file['name']);
	$documentName = basename($documentPathInfo['basename'], '.'.$documentPathInfo['extension']);
	//TODO: filter the name against a list of valid characters
	$conversionDirectory = $temporaryDirectory;
	$conversionDirectoryToUse = ensureMakeDirectory($conversionDirectory, $documentName);
	$documentPath = $conversionDirectoryToUse.DIRECTORY_SEPARATOR.$documentPathInfo['basename'];
	switch(DOCVERT_CLIENT_TYPE)
		{
		case 'web':
			if(is_uploaded_file($file['tmp_name']))
				{
				move_uploaded_file($file['tmp_name'], $documentPath);
				}
			else
				{
				copy($file['tmp_name'], $documentPath);
				silentlyUnlink($file['tmp_name']);
				}
			break;
		case 'command line':
			copy($file['tmp_name'], $documentPath);
			break;
		}
	return $documentPath;
	}

/**
 * is this an Oasis OpenDocument?
 * @return boolean
 */
function isAnOasisOpenDocument($fileUploadArray)
	{
	$isAnOasisOpenDocument = false;
	$validOasisOpenDocumentMimeType = 'application/vnd.oasis.opendocument.text'; // .text not .presentation or .graphics...
	if(stripos($fileUploadArray['type'], $validOasisOpenDocumentMimeType) === false)
		{
		$pathInfo = pathinfo($fileUploadArray['name']);
		$oasisOpenDocumentFileExtension = 'odt';
		if($pathInfo['extension'] == $oasisOpenDocumentFileExtension)
			{
			$isAnOasisOpenDocument = true;
			}
		}
	else
		{
		$isAnOasisOpenDocument = true;
		}
	if(!$isAnOasisOpenDocument)
		{
		$disallowNonOpenDocumentUploads = getConfigItem('disallowNonOpenDocumentUploads');
		if($disallowNonOpenDocumentUploads == 'true')
			{
			webServiceError('<h1>Only OpenDocument Files Allowed</h1><p>This installation has been configured to only allow OpenDocument uploads. This means that although MSWord is typically supported in Docvert, this particular server has chosen not to support it.</p><p>Supporting MS Word requires additional software that this server doesn\'t have.</p>');
			}
		}
	return $isAnOasisOpenDocument;
	}

/**
 * creates an Oasis OpenDocument from another Suite's file format, returns path
 * @return string
 */
function makeOasisOpenDocument($inputDocumentPath, $converter, $mockConversion = false)
	{
	if(!file_exists($inputDocumentPath) && !$mockConversion) webServiceError(500, 'In makeOasisOpenDocument(...) the inputDocumentPath points to a non-existant file.');
	$inputDocumentPath = convertPathSlashesForCurrentOperatingSystem($inputDocumentPath);
	$docvertCommandPath = DOCVERT_DIR.'core'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR;
	
	$commandTemplate = null;
	$extensionlessOutputDocumentPath = null;
	$outputDocumentPath = null;
	if(!$mockConversion)
		{
		$outputPathInfo = pathinfo($inputDocumentPath);
		$extensionlessOutputDocumentPath = $outputPathInfo['dirname'].DIRECTORY_SEPARATOR.basename($outputPathInfo['basename'], '.'.$outputPathInfo['extension']);
		$outputDocumentPath = $extensionlessOutputDocumentPath.'.odt';
		}
	if($outputDocumentPath == '.odt' && !$mockConversion)
		{
		webServiceError('Unable to determinate output filename. Please try again.', 500);
		}

	$commandTemplateVariable = array
		(
		'elevatePermissions' => '',
		'inputDocumentPath' => $inputDocumentPath,
		'outputDocumentPath' => $outputDocumentPath
		);

	$converters = Array(
		'openofficeorg'=>'OpenOffice.org 2+',
		'abiword'=>'Abiword',
		'jodconverter' => 'JODConverter',
		'pyodconverter' => 'PyODConverter');

	$docvertDir = dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR;
	$docvertWritableDir = $docvertDir.'writable'.DIRECTORY_SEPARATOR;

	if(!$converter)
		{
		$defaultConverter = '';
		$numberOfConvertersThatAreDisallowed = 0;
		foreach($converters as $converterId => $converterName)
			{
			$doNotUseConverter = getConfigItem('doNotUseConverter'.$converterId);
			if($doNotUseConverter == 'true')
				{
				$numberOfConvertersThatAreDisallowed++;
				}
			else
				{
				$defaultConverter = $converterId;
				}
			}

		if( $numberOfConvertersThatAreDisallowed+1 == count($converters) )
			{
			$converter = $defaultConverter;
			// There's only one choice, so don't bother asking the user
			}
		}
	
	$doNotUseConverter = getConfigItem('doNotUseConverter'.$converter);

	if($doNotUseConverter == 'true')
		{
		webServiceError('The chosen converter "'.$converter.'" has been disabled by the Docvert administrator.');
		}

	$operatingSystemFamily = getOperatingSystemFamily();
	switch($converter)
		{
		case 'openofficeorg':
			$commandTemplate = '{elevatePermissions} {scriptPath} {useXVFB} {macrosDocumentPath} {inputDocumentUrl} {outputDocumentUrl}';
			$commandTemplateVariable['macrosDocumentPath'] = $docvertCommandPath.'trusted-macros'.DIRECTORY_SEPARATOR.'macros.odt';
			$commandTemplateVariable['inputDocumentUrl'] = null;
			$commandTemplateVariable['outputDocumentUrl'] = null;
			$commandTemplateVariable['useXVFB'] = 'false';

			if(!$mockConversion)
				{
				$commandTemplateVariable['inputDocumentUrl'] = convertLocalPathToOpenOfficeOrgUrl($commandTemplateVariable['inputDocumentPath']);
				$commandTemplateVariable['outputDocumentUrl'] = convertLocalPathToOpenOfficeOrgUrl($commandTemplateVariable['outputDocumentPath']);
				}
			if($operatingSystemFamily == 'Windows')
				{
				$commandTemplateVariable['scriptPath'] = $docvertCommandPath.'windows-specific'.DIRECTORY_SEPARATOR.'convert-using-openoffice.org.bat';
				}
			elseif($operatingSystemFamily == 'Unix')
				{
				$disallowXVFB = getConfigItem('disallowXVFB');
				$commandTemplateVariable['elevatePermissions'] = 'sudo';
				$customUser = getConfigItem('runOpenOfficeAsCustomUser');
				if($customUser != '' && $customUser != 'root')
					{
					$commandTemplateVariable['elevatePermissions'] .= ' -u '.$customUser;
					}
				
				if($disallowXVFB == 'true')
					{
					$commandTemplateVariable['useXVFB'] = 'true';
					}
				$commandTemplateVariable['scriptPath'] = $docvertCommandPath.'unix-specific'.DIRECTORY_SEPARATOR.'convert-using-openoffice.org.sh';
				}
			break;
		case 'abiword':
			$commandTemplate = '{elevatePermissions} {scriptPath} {inputDocumentPath}';
			if($operatingSystemFamily == 'Windows')
				{
				$commandTemplateVariable['inputDocumentPath'] = $commandTemplateVariable['inputDocumentPath'];
				$commandTemplateVariable['scriptPath'] = $docvertCommandPath.'windows-specific'.DIRECTORY_SEPARATOR.'convert-using-abiword.bat';
				}
			elseif($operatingSystemFamily == 'Unix')
				{
				$commandTemplateVariable['elevatePermissions'] = 'sudo';
				$commandTemplateVariable['scriptPath'] = $docvertCommandPath.'unix-specific'.DIRECTORY_SEPARATOR.'convert-using-abiword.sh';
				}
			break;
		case 'jodconverter':
			$commandTemplate = '{elevatePermissions} java -jar {jodConverterJar} {inputDocumentPath} {outputDocumentPath}';
			$commandTemplateVariable['conversionDirectory'] = dirname(__FILE__).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'jodconverter';
			$commandTemplateVariable['jodConverterJar'] = dirname(__FILE__).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'jodconverter'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'jodconverter-2.1.1.jar';
			if(!file_exists($commandTemplateVariable['jodConverterJar']))
				{
				webServiceError('The JAR doesn\'t exist at '.$commandTemplateVariable['jodConverterJar']);
				}
			$commandTemplateVariable['inputDocumentUrl'] = convertLocalPathToOpenOfficeOrgUrl($commandTemplateVariable['inputDocumentPath']);
			$commandTemplateVariable['outputDocumentUrl'] = convertLocalPathToOpenOfficeOrgUrl($commandTemplateVariable['outputDocumentPath']);
			break;
		case 'pyodconverter':
			$commandTemplate = '{elevatePermissions} python {pyodConverterPath} {inputDocumentPath} {outputDocumentPath}';
			$commandTemplateVariable['conversionDirectory'] = dirname(__FILE__).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'jodconverter';
			$commandTemplateVariable['pyodConverterPath'] = dirname(__FILE__).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'pyodconverter'.DIRECTORY_SEPARATOR.'pyodconverter.py';
			if(!file_exists($commandTemplateVariable['pyodConverterPath']))
				{
				webServiceError('The python file doesn\'t exist at '.$commandTemplateVariable['pyodConverterPath']);
				}
			//$commandTemplateVariable['inputDocumentPath'] = basename($commandTemplateVariable['inputDocumentPath']);
			//$commandTemplateVariable['outputDocumentPath'] = basename($commandTemplateVariable['outputDocumentPath']);
			$commandTemplateVariable['inputDocumentUrl'] = convertLocalPathToOpenOfficeOrgUrl('/'.$commandTemplateVariable['inputDocumentPath']);
			$commandTemplateVariable['outputDocumentUrl'] = convertLocalPathToOpenOfficeOrgUrl('/'.$commandTemplateVariable['outputDocumentPath']);
			break;
		default:
			$additionalError = '';
			if($converter)
				{
				$additionalError = 'Was "'.revealXml($converter).'"';
				}
			else
				{
				$additionalError = ' Was empty.';
				}
			webServiceError('As part of the HTTP POST you must supply "converter=(openofficeorg|abiword|jodconverter)". '.$additionalError);
			break;
		}
	if(isset($commandTemplateVariable['scriptPath']) && !file_exists($commandTemplateVariable['scriptPath']))
		{
		webServiceError('The script path doesn\'t exist at '.$commandTemplateVariable['scriptPath']);
		}

	$command = $commandTemplate;
	foreach($commandTemplateVariable as $key => $value)
		{
		$replaceValue = $value;
		if($replaceValue)
			{
			if($operatingSystemFamily == 'Windows')
				{
				$replaceValue = '"'.$replaceValue.'"';
				}
			elseif($operatingSystemFamily == 'Unix')
				{
				$replaceValue = escapeshellcmd($replaceValue);
				}
			}
		$command = str_replace('{'.$key.'}', $replaceValue, $command);
		}

	$output = shellCommand($command);

	if(!file_exists($commandTemplateVariable['outputDocumentPath']) && !$mockConversion)
		{
		$errorMessage = '<p>Unable to generate an OpenDocument file from your upload.</p>';
		$errorMessage .= '<p>The command I tried to run was:</p>';
		$errorMessage .= '<blockquote><tt>'.revealXml($command).'</tt></blockquote>';
		$errorMessage .= '<p>In response to that command, the following was returned</p>';
		$errorMessage .= '<blockquote><tt>'.revealXml($output).'</tt></blockquote>';
		$errorMessage .= suggestFixesToCommandLineErrorMessage($output, $commandTemplateVariable, true);
		if(isset($commandTemplateVariable['scriptPath']) && !is_executable($commandTemplateVariable['scriptPath']))
			{
			if($operatingSystemFamily == 'Windows')
				{
				$errorMessage .= '<p>It appears that the conversion script isn\'t executable, but as you\'re running Windows I can\'t be sure of that. This may be a <a href="http://en.wikipedia.org/wiki/Red_herring">red herring</a> but check whether the script is executable to the web server user. The script can be found at,</p><blockquote><tt>'.revealXml($commandTemplateVariable['scriptPath']).'</tt></blockquote>';
				}
			elseif($operatingSystemFamily == 'Unix')
				{
				$errorMessage .= '<p>The conversion script isn\'t executable, so change its permissions (Eg. on Unix/Linux "chmod" it and ensure the web server user has access). The script can be found at, <blockquote><tt>'.revealXml($commandTemplateVariable['scriptPath']).'</tt></blockquote></p>';
				}
			}
		webServiceError($errorMessage);
		}
	else
		{
		$output = trim($output);
		if($output)
			{
			$output = $converter.': '.$output;
			silentlyAppendLineToLog($output, 'warning');
			}
		}
	if(!$mockConversion)
		{
		return $commandTemplateVariable['outputDocumentPath'];
		}
	else
		{
		return $output;
		}
	}

function suggestFixesToCommandLineErrorMessage($output, $commandTemplateVariable, $allowWildGuesses)
	{
	// "Diagnostics? Cool!" -- Pete Buzz --24 June 2006
	$suggestedFixes = '';
	if($allowWildGuesses)
		{
		if(DIRECTORY_SEPARATOR == '/' && isset($commandTemplateVariable['useXVFB']) && $commandTemplateVariable['useXVFB'] == false) //Unix
			{
			$suggestedFixes .= '<p>As you\'re using XVFB the problem may be that OpenOffice.org doesn\'t like it. Try disabling XVFB on the admin page to see if that fixes this.</p>';
			}
		}
	if(trim($output))
		{
		if(stripos($output, 'password') !== false)
			{
			$suggestedFixes .= '<p>As it\'s asking for a password there\'s a <tt>sudo</tt> problem so read <a href="doc/install.txt">install.txt</a> about "<tt>visudo</tt>"</p>';
			}
		if(stripos($output, 'the system cannot find the path specified') !== false)
			{
			if(!isset($commandTemplateVariable['scriptPath']))
				{
				$commandTemplateVariable['scriptPath'] = '';
				}
			$suggestedFixes .= '<p>As it\'s saying it cannot find the path this probably means your <tt>'.basename(revealXml($commandTemplateVariable['scriptPath'])).'</tt> isn\'t pointing at an application. If so, try the install docs...</p>';
			}
		if(stripos($output, 'command not found') !== false)
			{
			if(stripos($output, 'oowriter') !== false)
				{
				$suggestedFixes .= '<p>As it\'s saying it can\'t find oowriter this means either that OpenOffice.org 2 is not installed, or that the script isn\'t pointing to the location of OpenOffice.org.</p>';
				}
			if(stripos($output, 'xvfb-run') !== false)
				{
				$suggestedFixes .= '<p>As it\'s saying it can\'t find <b>xvfb-run</b> this means either that it\'s not installed, or that the script <tt>'.basename(revealXml($commandTemplateVariable['scriptPath'])).'</tt> isn\'t pointing at the file.</p>';
				}
			else
				{
				$suggestedFixes .= '<p>As it\'s saying it can\'t find that command it means that this command is not installed, or that the script isn\'t pointing to the command.</p>';
				}
			}
		if(stripos($output, 'X11') !== false || stripos($output, 'refused by server Xlib') !== false )
			{
			$runAsUser = 'root';
			$runAsCustomUserConfigurationPath = dirname(dirname(__file__)).DIRECTORY_SEPARATOR.'writable'.DIRECTORY_SEPARATOR.'use-custom-user.php';

			if(file_exists($runAsCustomUserConfigurationPath))
				{
				include($runAsCustomUserConfigurationPath);
				$runAsUser = trim($customUser);
				}

			$suggestedFixes .= '<p>As it\'s complaining about "X11" the problem might be that the "'.revealXml($runAsUser).'" user can\'t access your desktop, so allow them by using <tt style="font-weight:bold">xhost</tt>. If that\'s the problem you can allow '.$runAsUser.' access to your desktop by typing this command: <blockquote><tt>sudo xhost local:'.$runAsUser.'</tt></blockquote></p>';
			}

		if(stripos($output, 'no passwd entry for'))
			{
			$suggestedFixes .= '<p>As it\'s complaining about the lack of a password entry, this probably means that you haven\'t configured visudo for this user. Read the <a href="doc/install.txt">install.txt</a> about this.</p>';
			}

		if(stripos($output, 'xvfb-run: not found') !== false)
			{
			$suggestedFixes .= '<p>Did you install XVFB? See <a href="doc/install.txt">install.txt</a> for install instructions or disable XVFB from the admin page.';
			}

		if(strpos($output, 'CRITICAL') !== false)
			{
			$suggestedFixes .= '<p>A critical error... in capital letters even. Looks like that software which was external to Docvert crashed (Eg. a problem in that piece of software).</p>';
			}

		if(stripos($output, 'wmf2gd: not found') !== false)
			{
			$suggestedFixes .= '<p>...so install the wmf libraries as per <a href="doc/install.txt">install.txt</a>.</p>';
			}
		if(stripos($output, 'Terminated DISPLAY') !== false)
			{
			$suggestedFixes .= '<p>The X server was killed (hopefully by you...if not it might have been an over eager cleanup process).</p>';
			}
		if(stripos($output, 'locale') !== false) //never been the cause of errors for me
			{
			$suggestedFixes .= '<p><i>Note:</i> Although it\'s also complaining about locale support that particular problem hasn\'t been the actual cause of errors for me.</p>';
			}
		if( (stripos($output, 'connection failed') !== false && stripos($output, 'running and listening') !== false) || stripos($output, 'failed to connect to OpenOffice.org') )
			{
			$suggestedFixes .= '<p>This error message probably means that PyODConverter or JODConverter is complaining about OpenOffice.org not running. If so, start OpenOffice.org in listening mode by using this command,</p><blockquote><tt>soffice -accept="socket,port=8100;urp;"</tt></blockquote><p>Or this to do the same but not show OpenOffice.org on the desktop,</p><blockquote><tt>soffice -headless -accept="socket,port=8100;urp;"</tt></blockquote>';
			}
		if( (stripos($output, 'jodconverter') !== false || stripos($output, 'pyodconverter') !== false) && ( (stripos($output, 'URL seems to be an unsupported one') !== false || stripos($output, 'ErrorCodeIOException') !== false) ) )
			{
			$temporaryDirectoryMessage = '';
			if(isset($commandTemplateVariable['outputDocumentPath']))
				{
				$temporaryDirectoryMessage = dirname($commandTemplateVariable['outputDocumentPath']);
				$temporaryDirectoryMessage = ' ("'.$temporaryDirectoryMessage.'") ';
				}
			$suggestedFixes .= '<p>PyODConverter/JODConverter or OpenOffice.org seems to be having some problems... it may be that OpenOffice is unable to save to the temporary directory '.revealXml($temporaryDirectoryMessage).'.</p><p>If so, be sure that the user you started OpenOffice as had write access to that temporary directory.</p><p>Regardless, the problem seems to be occuring outside Docvert so try getting PyODConverter/JODConverter running from the command line first.</p><p><small>(But if PyODConverter/JODConverter is running correctly then let me know because then Docvert must be at fault)</small></p>';
			}
		if(stripos($output, 'jodconverter') !== false && stripos($output, 'inputFile doesn\'t exist') !== false)
			{
			$suggestedFixes .= '<p>OpenOffice.org appears to think the file doesn\'t exist but it does. The inputPath is probably malformed. Please post about this entier error message to the mailing list.';
			}
		}
	if(ini_get('safe_mode'))
		{
		$suggestedFixes .= '<p>Note: PHP\'s Safe Mode is enabled and this may restrict Docvert from using this command. You can either disable Safe Mode, or add Docvert\'s command directory to safe_mode_exec_dir in your php.ini.</p>';
		}
	return $suggestedFixes;
	}

/**
 * Start OpenOfficeOrg on the desktop in order to let the user configure it.
 * Not to be called remotely -- this actually starts it up on their desktop.
 */
function setupOpenOfficeOrg()
	{
	set_time_limit(60 * 2);
	include_once('security.php');
	$adminPassword = Security::getAdminPassword();
	if($adminPassword === FALSE)
		{
		webServiceError('Refusing to start OpenOffice.org because a password hasn\'t been set. See install.txt for details on how to do this.', 300);
		}
	else
		{
		session_start();
		if($_SESSION['docvert_p'] != $adminPassword) webServiceError('Refusing to start OpenOffice.org due to incorrect password. Your password is "'.$_COOKIE['docvert_p'].'"', 300); 
		}
	$output = makeOasisOpenDocument(null, 'openofficeorg', true);
	$body = null;
	$body .= '<h1>Docvert tried to start OpenOffice.org on your desktop</h1>';
	if(trim($output) != '' && stripos($output, 'uninitialized value') === false && stripos($output, 'stat: missing operand') === false)
		{
		$body .= '<p>...but this appears to have failed. This is what was returned:</p><blockquote><tt>'.$output.'</tt></blockquote>';
		$body .= suggestFixesToCommandLineErrorMessage($output, null, false);
		webServiceError($body);
		}
	else
		{
		$body .= '<p>Hopefully it worked and you were able to configure OpenOffice.org to use Docvert\'s trusted-macros directory as per the <a href="doc/install.txt">install.txt</a>.</p><p>If not, it\'s likely that OpenOffice.org has crashed and so you\'ll need to look at the process list and kill any existing OpenOffice.org processes.</p>';
		//'<p>Here\'s the output (if any) from OpenOffice.org,</p><blockquote><tt>'.$output.'</tt></blockquote>';
		webServiceError($body, 200);
		}
	}

function substringAfter($haystack, $needle)
	{
	return substr($haystack, strpos($haystack, $needle) + strlen($needle));
	}

function substringBefore($haystack, $needle)
	{
	return substr($haystack, 0, strpos($haystack, $needle));
	}

function getOperatingSystemFamily()
	{
	return DIRECTORY_SEPARATOR == '\\' ? 'Windows' : 'Unix';
	}

/**
 * gets the useful stuff from an Oasis OpenDocument archive
 */
function extractUsefulOasisOpenDocumentFiles($oasisOpenDocumentPath)
	{
	if(!trim($oasisOpenDocumentPath))
		{
		webServiceError('Internal error in Docvert. <tt>extractUsefulOasisOpenDocumentFiles(...)</tt> was called with an empty path. Value was "'.$oasisOpenDocumentPath.'"');
		}
	include_once(DOCVERT_DIR.'core/lib/pclzip-2-6/pclzip.lib.php');
	$unknownImageIndex = 1;
	$documentDirectory = dirname($oasisOpenDocumentPath).DIRECTORY_SEPARATOR;
	$archive = new PclZip($oasisOpenDocumentPath);
	$odfObjects = array();
	if (($archivedFiles = $archive->listContent()) == 0)
		{
		webServiceError('Error unzipping Oasis OpenDocument: '.$archive->errorInfo(true));
		}
	foreach ($archivedFiles as $archivedFile)
		{
		if(isAnOasisOpenDocumentFileWeWant($archivedFile['filename']))
			{
			$archive->extractByIndex($archivedFile['index'], PCLZIP_OPT_PATH, $documentDirectory, PCLZIP_OPT_REMOVE_ALL_PATH);
			//print basename($archivedFile['filename']).'<br />';
			if(stringEndsWith($archivedFile['filename'], 'xml') || basename($archivedFile['filename']) == 'thumbnail.png')
				{
				$oldPath = $documentDirectory.basename($archivedFile['filename']);
				$newPath = $documentDirectory.'docvert-'.basename($archivedFile['filename']);
				if(!file_exists($oldPath))
					{
					webServiceError('Source path does not exist at "'.$oldPath.'"');
					}
				if(!rename($oldPath, $newPath))
					{
					//if(file_exists($oldPath) || !file_exists($newPath))
					webServiceError('Unable to rename '.$oldPath.' to '.$newPath.' despite the target directory existing.');
					}
				if(!file_exists(dirname($newPath)))
					{
					webServiceError('Destination directory does not exist at "'.dirname($newPath).'"');
					}
				if(!file_exists($newPath))
					{
					webServiceError('Destination path file does not exist at "'.$newPath.'"');
					}
				}
			elseif(stringStartsWith(strtolower($archivedFile['filename']), 'objectreplacements'))
				{
				$oldPath = $documentDirectory.basename($archivedFile['filename']);
				if(!function_exists('getimagesize'))
					{
					$errorMessage = '<div class="error"><p>The file contains embedded ODF Objects (similar in purpose to OLE Objects) but I\'m unable to detect the data type because your PHP doesn\'t have getimagesize(). You should add the GD extension to your PHP to fix this.</p></div>';
					$testResultsPath = $documentDirectory.'test.html';
					file_put_contents($testResultsPath, $errorMessage, FILE_APPEND);
					}
				else
					{
					$fileExtension = 'wmf';
					$imageMetadata = null;
					$imageSize = getimagesize($oldPath, $imageMetadata);
					/*
					* getimagesize returns a number which means a file format
					* 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP,
					* 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order),
					* 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF,
					* 15 = WBMP, 16 = XBM
					*/
					$imageTypes = array("GIF", "JPG", "PNG", "SWF", "PSD", "BMP", "TIFF", "TIFF", "JPC", "JP2", "JPX", "JB2", "SWC", "IFF", "WBMP", "XBM");
					if(trim($imageMetadata[1]) != "" && $imageMetadata[1] < count($imageTypes))
						{
						$imageTypeIndex = $imageMetadata[1] - 1;
						$fileExtension = strtolower($imageTypes[$imageTypeIndex]);
						//die('File extension: "'.$fileExtension.':'.$imageTypes[$imageTypeIndex].'" ['.$imageMetadata[1].']');
						}
					$newPath = $documentDirectory.'image'.$unknownImageIndex.'.'.$fileExtension;
					if(!rename($oldPath, $newPath))
						{
						webServiceError('Unable to rename '.$oldPath.' to '.$newPath.' despite the target directory existing.');
						}
					$unknownImageIndex++;
					$odfObjects[] = array($archivedFile['filename'], basename($newPath), $fileExtension);
					}
				}
			}
		}
	$contentXmlPath = $documentDirectory.'docvert-content.xml';
	if(count($odfObjects) >= 1 && file_exists($contentXmlPath))
		{
		// Rename image
		$contentXml = file_get_contents($contentXmlPath);
		foreach($odfObjects as $odfObject)
			{
			$contentXml = str_replace('./'.$odfObject[0].'"', $odfObject[1].'" type="'.$odfObject[2].'"', $contentXml);
			$contentXml = str_replace($odfObject[0].'"', $odfObject[1].'" type="'.$odfObject[2].'"', $contentXml);
			}
		//displayXmlString($contentXml);
		file_put_contents($contentXmlPath, $contentXml);
		}
	}

/**
 * is this a file inside an OpenDocument file we want?
 */
function isAnOasisOpenDocumentFileWeWant($filename)
	{
	$fileExtension = strrchr($filename, '.');
	$directoryName = strtolower(dirname($filename));
	$wantedFileNames = array('content.xml', 'meta.xml', 'styles.xml');
	$wantedFileExtensions = array('.gif', '.jpeg', '.jpg', '.bmp', '.png', '.wmf', '.emf', '.svg');
	$wantedDirectories = array('objectreplacements');
	return (in_array($filename, $wantedFileNames) || in_array($fileExtension, $wantedFileExtensions) || in_array($directoryName, $wantedDirectories));
	}

function convertLocalPathToOpenOfficeOrgUrl($localPath)
	{
	$localPath = str_replace('\\','/',$localPath);
	$localPath = rawurlencode($localPath);
	$localPath = 'file:///'.$localPath;
	$localPath = str_replace('%2F', '/', $localPath);
	return $localPath;
	}

function getTemporaryDirectory()
	{
	$temporaryDirectory = null;
	$temporaryFile = tempnam('xxx', 'docvert'); // 'xxx' to give a directory that doesn't exist as empty string to tempnam on Windows gives the wrong directory
	$makeInsideDirectory = dirname($temporaryFile);
	$exitAfterXLoops = 0;
	silentlyUnlink($temporaryFile);
	$temporaryDirectory = getTemporaryDirectoryInsideDirectory($makeInsideDirectory);
	return $temporaryDirectory;
	}

function getTemporaryDirectoryInsideDirectory($makeInsideDirectory, $prefix = 'docvert-')
	{
	if(substr($makeInsideDirectory, -1) != DIRECTORY_SEPARATOR)
		{
		$makeInsideDirectory .= DIRECTORY_SEPARATOR;
		}
	$exitAfterXLoops = 0;
	do
		{
		$temporaryDirectory = $makeInsideDirectory.$prefix.mt_rand(0, 9999999);
		$exitAfterXLoops++;
		if($exitAfterXLoops >= 10)
			{
			webServiceError('Problem creating directory at '.$temporaryDirectory.' and so after '.$exitAfterXLoops.' attempts I gave up.');
			}
		}
	while(@!mkdir($temporaryDirectory, 0777));
	return $temporaryDirectory;
	}

function getTemporaryFile()
	{
	$temporaryDirectory = null;
	$temporaryFile = tempnam('xxx', 'docvert'); // 'xxx' to give a directory that doesn't exist as empty string to tempnam on Windows gives the wrong directory
	return $temporaryFile;
	}

function applyPipeline($contentPath, $pipelineToUse, $autoPipeline, $previewDirectory, $skipAheadToDocbook=false)
	{
	if(!trim($contentPath)) webServiceError('No content.xml file given.');
	if(!file_exists($contentPath)) webServiceError('Unable to find '.basename($contentPath).' file in "'.dirname($contentPath).'"');
	$contentDirectory = dirname($contentPath);
	$pipelineDirectory = DOCVERT_DIR.'pipeline'.DIRECTORY_SEPARATOR.$pipelineToUse.DIRECTORY_SEPARATOR;
	$pipelinePath = $pipelineDirectory.'pipeline.xml';
	if(!file_exists($pipelinePath)) webServiceError('Couldn\'t find the '.$pipelineToUse.'\'s pipeline.xml definition file at '.$pipelinePath.'. If the file is there check permissions, and if the problem persists note that some web servers cache permissions so you might need to bounce the web server to make it notice your permission changes.');
	$pipelineString = file_get_contents($pipelinePath);
	$pipelineString = removeXmlDeclaration($pipelineString);
	$pipelineString = trim($pipelineString);
	
	
	if(strpos($pipelineString, '<autopipeline') !== false)
		{
		$autoPipelineString = substr($pipelineString, strpos($pipelineString, '<autopipeline>') + 14);
		$autoPipelineString = substr($autoPipelineString, 0, strpos($autoPipelineString, '</autopipeline>'));
		$autoPipelinesDirectory = dirname(__FILE__).DIRECTORY_SEPARATOR.'auto-pipelines'.DIRECTORY_SEPARATOR;
		$chosenAutoPipeline = $autoPipeline;
		if(!trim($chosenAutoPipeline))
			{
			$defaultAutoPipelines = glob($autoPipelinesDirectory.'*.default.xml');
			if(count($defaultAutoPipelines) == 1)
				{
				$chosenAutoPipeline = substringBefore($defaultAutoPipelines[0], '.xml');
				}
			$chosenAutoPipeline = str_replace($autoPipelinesDirectory, '', $chosenAutoPipeline);
			}

		$chosenAutoPipeline = str_replace('.default', '', $chosenAutoPipeline);

		if(strpos($chosenAutoPipeline, '.') === true || strpos($chosenAutoPipeline, '/') === true || strpos($chosenAutoPipeline, '\\') === true)
			{				
			webServiceError('The <tt>autopipeline</tt> variable must not contain full-stops "." or slashes "/" and "\\". It was "'.$chosenAutoPipeline.'"', '400 Bad Request');
			}

		$autoPipelinePath = $autoPipelinesDirectory.$chosenAutoPipeline.'.xml';

		if(!trim($chosenAutoPipeline))
			{
			webServiceError('For <tt>&lt;autopipeline&gt;s</tt> the <tt>\'autotemplate\'</tt> variable must be given as part of the HTTP POST. It was empty.', '400 Bad Request');
			}
		elseif(!file_exists($autoPipelinePath))
			{
			$autoPipelinePath = $autoPipelinesDirectory.$chosenAutoPipeline.'.default.xml';
			if(!file_exists($autoPipelinePath))
				{
				webServiceError('An autotemplate for the autoTemplate level of "'.$chosenAutoPipeline.'" isn\'t supported in this version of Docvert. Programmers may create a pipeline and save it to <tt>"'.$autoPipelinePath.'.xml"</tt> in order to support this.', '400 Bad Request'.$autoPipelinePath);
				}
			}

		$pipelineString = file_get_contents($autoPipelinePath);
		if(stripos($pipelineString, '{{custom-stages}}') === false)
			{
			webServiceError('Auto pipeline does not contain {{custom-stages}} placeholder at '.$autoPipelinePath.'. Contents are <blockquote>'.revealXml($pipelineString).'</blockquote>');
			}
		$pipelineString = str_replace('{{custom-stages}}', $autoPipelineString, $pipelineString);
		}
	$pipelineString = substr($pipelineString, strpos($pipelineString, '<pipeline>') + 10);
	$pipelineString = substr($pipelineString, 0, strpos($pipelineString, '</pipeline>'));

	if($skipAheadToDocbook)
		{
		$toDocbookPartOfPipelinePattern = "/.*?ToDocBook[^>]*?>/s";
		$pipelineString = preg_replace($toDocbookPartOfPipelinePattern, '', $pipelineString);
		}

	$pipelineStages = xmlStringToArray($pipelineString);
	$currentXml = file_get_contents($contentPath);
	$currentXml = fixImagePaths($currentXml);
	$pipelineSettings = array("pipeline" => $pipelineToUse, "autopipeline" => $autoPipeline);
	processAPipelineLevel($pipelineStages, $currentXml, $pipelineDirectory, $contentDirectory, $previewDirectory, $pipelineSettings);
	$testResultsPath = $contentDirectory.DIRECTORY_SEPARATOR.'test.html';
	if(file_exists($testResultsPath))
		{
		$title = 'Document Unit Tests';
		$body = file_get_contents($testResultsPath)."\n";
		$template = getXHTMLTemplate();
		$template = str_replace('{{title}}', $title, $template);
		$template = str_replace('{{body}}', '<h1>Document Notices</h1>'."\n".'<p class="timestamp"><span class="time">'.date('r').'</span></p>'.$body, $template);
		$template = str_replace('{{head}}', '<style type="text/css"> body {font-size:small} .error{background:#ffeeee;border: solid 1px red; padding:10px;margin-bottom:10px} h1{font-size:medium;} p {margin-top:0px;} .error h1{margin:0px;padding:0px;}  .timestamp {font-size:x-small;color:#999999} .validation{background:#eeeeff; border: solid 1px blue; padding:10px;margin-bottom:10px} p {margin:0px;} .warning {background:#eeeeff; border: solid 1px blue; padding:10px;margin-bottom:10px} </style>', $template);
		file_put_contents($testResultsPath, $template);
		}
	$stylesXmlPath = $contentDirectory.DIRECTORY_SEPARATOR.'styles.xml';
	if(file_exists($stylesXmlPath))
		{
		silentlyUnlink($stylesXmlPath);
		}
	}

function fixImagePaths(&$currentXml)
	{
	// extractUsefulOasisOpenDocumentFiles() extracts pictures without path, so fix references to images
	$currentXml = str_replace('xlink:href="Pictures/', 'xlink:href="', $currentXml); 
	return $currentXml;
	}

/**
 * Looks inside a directory and uses the list of files as the basis for a filename name
 * of the Docvert zip download.
*/
function chooseNameOfZipFile($path)
	{
	$filenames = glob($path.'*');
	$suggestedFileName = "";
	foreach($filenames as $filename)
		{
		$suggestedFileName .= basename($filename);
		}
	$suggestedFileName = str_replace(' ', '-', $suggestedFileName);
	$suggestedFileName = str_replace('.', '-', $suggestedFileName);
	$suggestedFileName = strtolower($suggestedFileName.".zip");
	while(strpos($suggestedFileName, '--') !== false)
		{
		$suggestedFileName = str_replace('--', '-', $suggestedFileName);
		}
	$zipFilePath = $allDocumentsPreviewDirectory.$suggestedFileName;
	return $zipFilePath;
	}

/**
 handles pipeline loop process
*/
function processAPipelineLevel(&$pipelineStages, $currentXml, $pipelineDirectory, $contentDirectory, $previewDirectory, $pipelineSettings, $loopDepth = '', $depthArray = null)
	{
	$foreachIndex = null;
	foreach($pipelineStages as $key => $pipelineStage)
		{
		if(substr($key, 0, 2) != '__')
			{
			$elementAttributes = &$pipelineStage['__attributes'];
			if(!is_array($elementAttributes)) webServiceError('Internal error, this should not happen. No attributes found on node.');
			$foreachIndex++;
			if($elementAttributes['process'] == 'Loop')
				{
				$depthArray[] = 'YouHaveAPipelineError-WrongDepthError';
				$lastItemIndex = count($depthArray) - 1;
				$numberOfLoops = 0;
				if(array_key_exists('numberOfTimes', $elementAttributes))
					{
					if(substr($elementAttributes['numberOfTimes'], 0, 11) == 'xpathCount:')
						{
						$xpathCountTemplate = substr($elementAttributes['numberOfTimes'], 11);
						$xpathCount = processDepthTemplate($xpathCountTemplate, $depthArray);
						if(strstr($xpathCount, '{') || strstr($xpathCount, 'YouHaveAPipelineError-WrongDepthError'))
							{
							webServiceError('In the pipeline definition there\'s a {../LoopIndex} variable that steps up too far, beyond any parent loop.');
							}
						$xsltString = file_get_contents(DOCVERT_DIR.'core'.DIRECTORY_SEPARATOR.'transform'.DIRECTORY_SEPARATOR.'count-nodes.xsl-fragment');
						$xsltString = str_replace('{{xpathCount}}',$xpathCount,$xsltString);
						$numberOfLoops = xsltTransformWithXsltString($currentXml, $xsltString);
						//displayXmlString($currentXml);
						}
					elseif(substr($elementAttributes['numberOfTimes'], 0, 10) == 'substring:')
						{
						$substring = substr($elementAttributes['numberOfTimes'], 10);
						$numberOfLoops = substr_count($currentXml, $substring);
						}
					elseif(substr($elementAttributes['numberOfTimes'], 0, 7) == 'number:')
						{
						$substring = substr($elementAttributes['numberOfTimes'], 7);
						$numberOfLoops = substr_count($currentXml, $substring);
						}
					else
						{
						webServiceError('Although a pipeline loop contains a numberOfTimes attribute the value needs a "substring:", "xpathCount:", or "number:" prefix.');
						}
					}
				else
					{
					webServiceError('Loops in pipelines need a numberOfTimes attribute.');
					}
				for($loopIndex = 1; $loopIndex <= $numberOfLoops; $loopIndex++)
					{
					$depthArray[$lastItemIndex] = $loopIndex;
					$newLoopDepth = $loopDepth;
					if($newLoopDepth)
						{
						$newLoopDepth .= '-';
						}
					$newLoopDepth .= $loopIndex;
					processAPipelineLevel($pipelineStage['__children'], $currentXml, $pipelineDirectory, $contentDirectory, $previewDirectory, $pipelineSettings, $newLoopDepth, $depthArray);
					}
				}
			else
				{
				$currentXml = processAPipelineStage($elementAttributes, $currentXml, $pipelineDirectory, $contentDirectory, $loopDepth, $depthArray, $previewDirectory, $pipelineSettings);
				}
			}

		}
	}

/**
  process a pipeline stage
*/
function processAPipelineStage($elementAttributes, $currentXml, $pipelineDirectory, $contentDirectory, $loopDepth, $depthArray, $previewDirectory, $pipelineSettings)
	{
	if(!array_key_exists('process',$elementAttributes)) webServiceError('All pipeline stages must have a process attribute.');
	$processPath = DOCVERT_DIR.'core/process/'.$elementAttributes['process'].'.php';
	if(file_exists($processPath))
		{
		require_once($processPath);
		if(class_exists($elementAttributes['process']))
			{
			$docvertTransformDirectory = DOCVERT_DIR.'core'.DIRECTORY_SEPARATOR.'transform'.DIRECTORY_SEPARATOR;
			$pipelineProcess = $elementAttributes['process'];
			$pipelineStage = new $pipelineProcess($elementAttributes, $pipelineDirectory, $contentDirectory, $docvertTransformDirectory, $loopDepth, $depthArray, $previewDirectory, $pipelineSettings);
			$currentXml = $pipelineStage->process($currentXml);
			}
		else
			{
			webServiceError('A pipeline stage requires <b>'.$elementAttributes['process'].'</b> and although <tt>'.$elementAttributes['process'].'.php</tt> exists it doesn\'t implement a class by this name (classes are case sensitive).');
			}
		}
	else
		{
		webServiceError('A pipeline stage requires <b>'.$elementAttributes['process'].'</b> but <tt>'.$elementAttributes['process'].'.php</tt> was not found (specifically was not found at '.$processPath.').');
		}
	return $currentXml;
	}

/**
 * returns a string with placeholder references to depth replaced.
 *	Eg, "blahblah{../LoopIndex} {../../LoopIndex} is replaced accordingly. "
 * @return string
*/
function processDepthTemplate($depthTemplate, $depthArray, $debug = false)
	{
	$numberOfDepthsToCheck = count($depthArray);
	if($numberOfDepthsToCheck != 0)
		{
		$depthAtom = '../';
		for($depthIndex = $numberOfDepthsToCheck; $depthIndex > 0; $depthIndex--)
			{
			$thisDepthString = '';
			$inverseDepth = $numberOfDepthsToCheck - $depthIndex;
			for($numberOfAtoms = 0; $numberOfAtoms < $inverseDepth; $numberOfAtoms++)
				{
				$thisDepthString .= $depthAtom;
				}
			$searchString = '{'.$thisDepthString.'LoopIndex'.'}';
			if($debug)
				{
				print $searchString." &amp; ".$depthArray[$depthIndex].' = '.$depthTemplate."<hr />";
				}
			$depthTemplate = str_replace($searchString, $depthArray[$depthIndex - 1], $depthTemplate);
			}
		}
	return $depthTemplate;
	}

/**
 * based a string of XML, see whether it's OpenDocument or OpenOffice and return an identifying string
*/
function detectDocumentType($xmlString)
	{
	$documentType = null;
	if(strpos($xmlString,'"urn:oasis:names:tc:opendocument:xmlns:office:1.0"') !== false)
		{
		$documentType = 'OpenDocument1.0';
		}
	elseif(strpos($xmlString,'"http://openoffice.org/2000/office"') !== false)
		{
		$documentType = 'OpenOffice1.x';
		}
	return $documentType;
	}

/**
 * what the directory name says, and returns the path of the zip
 * @return string
*/
function zipAndDeleteTemporaryFiles($temporaryDirectory)
	{
	$temporaryName = basename($temporaryDirectory, ".dir");
	$temporaryNamePath = dirname($temporaryDirectory).DIRECTORY_SEPARATOR.$temporaryName;
	$zipFilePath = $temporaryNamePath.'.zip';
	$zipFilePath = zipFiles($temporaryDirectory, $zipFilePath);
	deleteDirectoryRecursively($temporaryDirectory);
	if(file_exists($temporaryDirectory))
		{
		silentlyAppendLineToLog('Unable to clean up after conversion, certain temporary files still exist.', 'error');
		}
	return $zipFilePath;
	}

function zipFiles($path, $zipFilePath)
	{
	require_once(DOCVERT_DIR.'core/lib/pclzip-2-6/pclzip.lib.php');
	$archive = new PclZip($zipFilePath);
	$baseDirectoryToRemoveForZipping = $path.DIRECTORY_SEPARATOR;
	if(strpos($path, ":"))
		{
		// PCLZip will remove path for zipping but it doesn't want the drive letter prefix (eg remove the "c:" part)
		$baseDirectoryToRemoveForZipping = substringAfter($baseDirectoryToRemoveForZipping, ':');
		}
	$returnCode = $archive->create($path, PCLZIP_OPT_REMOVE_PATH, $baseDirectoryToRemoveForZipping);
	if($returnCode == 0)
		{
		webServiceError('Problem zipping files: '.$archive->errorInfo(true));
		}
	return $zipFilePath;
	}

function convertPathSlashesForCurrentOperatingSystem($localPath)
	{
	$operatingSystemFamily = getOperatingSystemFamily();
	if($operatingSystemFamily == 'Windows')
		{
		$localPath = str_replace('/', DIRECTORY_SEPARATOR, $localPath);
		}
	elseif($operatingSystemFamily == 'Unix')
		{
		$localPath = str_replace('\\', DIRECTORY_SEPARATOR, $localPath);		
		}
	return $localPath;
	}

function deleteDirectoryRecursively($directoryPath)
	{
	$directoryPath = convertPathSlashesForCurrentOperatingSystem($directoryPath);
	if($files = glob($directoryPath.DIRECTORY_SEPARATOR.'*'))
		{
		foreach($files as $file)
			{
			if(is_dir($file))
				{
				deleteDirectoryRecursively($file);
				}
			else
				{
				silentlyUnlink($file);
				}
			}
		}
	$operatingSystemFamily = getOperatingSystemFamily();
	$detailedError = '';
	if($operatingSystemFamily == 'Windows')
		{
		$rmdirCommand = 'rmdir /s /q '.$directoryPath;
		$response = shellCommand($rmdirCommand);
		$response = trim($response);
		//file_put_contents('c:\\results.txt', "\r\n".$response."\r\n", FILE_APPEND);
		if(stripos($response, 'process cannot access the file') !== false)
			{
			$detailedError .= '. Reason: '.$response;
			}
		else
			{
			$detailedError .= '. Running command "'.$rmdirCommand.'" '.$response;
			}
		}

	if(@!rmdir($directoryPath))
		{
		$detailedError = null;
		$contentsOfDirectory = implode(glob($directoryPath.DIRECTORY_SEPARATOR.'*'), ', ');
		$contentsOfDirectory = substr($contentsOfDirectory, 0, strlen($contentsOfDirectory) - 1);
		if(trim($contentsOfDirectory))
			{
			$detailedError .= 'Directory contains '.$contentsOfDirectory.'. ';
			}
		if(file_exists($directoryPath))
			{
			silentlyAppendLineToLog('Unable to delete directory '.$directoryPath.$detailedError, 'error');
			}
		}
	}

function silentlyAppendLineToLog($messageLine, $logType)
	{
	switch($logType)
		{
		case 'error':
		case 'warning':
		case 'debug':
		case 'security':
			break;
		default:
			webServiceError(500, 'Invalid call to silentlyAppendLineToLog(...) with $logType="'.$logType.'"');
		}
	$temporaryFile = tempnam('xxx', 'docvert');
	$temporaryDirectoryPath = dirname($temporaryFile);
	silentlyUnlink($temporaryFile);
	$messageLine = '['.date('r').'] ['.$logType.'] '.$messageLine;
	$logFilePath = $temporaryDirectoryPath;
	if(stringRight($logFilePath, 1) != DIRECTORY_SEPARATOR)
		{
		$logFilePath .= DIRECTORY_SEPARATOR;
		}
	$logFilePath .= 'docvert-'.$logType.'.txt';
	file_put_contents($logFilePath, revealXml($messageLine)."\r\n", FILE_APPEND);
	}

/**
 * For displaying XML/HTML.
 * Often used to stop Cross Site Scripting attacks.
 * @return string
*/
function revealXml($xmlString)
	{
	$xmlString = str_replace('&', '&amp;', $xmlString);
	$xmlString = str_replace('<', '&lt;', $xmlString);
	$xmlString = str_replace('>', '&gt;', $xmlString);
	return $xmlString;
	}

/**
 * Returns x number of characters on the righthand of a string.
*/
function stringRight($string, $numberOfCharacters)
	{
	return substr($string, strlen($string) - $numberOfCharacters);
	}

function stringLeft($string, $numberOfCharacters)
	{
	return substr($string, 0, $numberOfCharacters);
	}

/**
 * Recursively deletes a file but doesn't complain if it wasn't able to
 * Eg. due to permissions
*/
function silentlyUnlink($path)
	{
	if(is_dir($path))
		{
		$pathContainsItems = glob($path.DIRECTORY_SEPARATOR.'*');
		foreach($pathContainsItems as $pathContainsItem)
			{
			silentlyUnlink($pathContainsItem);
			}
		}
	if(@!unlink($path)) silentlyAppendLineToLog('Unable to delete file '.$path, 'error');
	}


function moveFile($source, $destination)
	{
	return rename($source, $destination);
	}

function containsString($haystack, $needle)
	{
	return (strpos($haystack, $needle) !== false);
	}

/**
 * Whether a string starts with a string. Mostly used for readability.
 * @returns BOOL
*/
function stringStartsWith($haystack, $needle)
	{
	return (stringLeft($haystack, strlen($needle)) == $needle);
	}

/**
 * Whether a string ends with a string. Mostly used for readability.
 * @returns BOOL
*/
function stringEndsWith($haystack, $needle)
	{
	//print stringRight($haystack, strlen($needle)).' == '.$needle.'<br />';
	return (stringRight($haystack, strlen($needle)) == $needle);
	}

function getPhpVersion()
	{
	return PHP_VERSION;
	}

/*
 * Overriding default error messages and this function is assigned at the top of this file.
 * See http://nz.php.net/errorfunc
*/
function phpErrorHandler($errorLevel, $message, $file, $line)
	{
	//$errorLevelToDescribeMerelyDeprecatedWarnings = 2048;
	//if($errorLevel < $errorLevelToDescribeMerelyDeprecatedWarnings)
	//this uses stripos rather than docvert containsString() in order to be more stand alone
	if(
		stripos($message, "rmdir") === false &&
		stripos($message, "mkdir") === false &&
		stripos($message, 'ftp_login') === false &&
		stripos($message, 'imagecreatefromstring') === false &&
		stripos($message, '404 Not Found') === false &&
		(stripos($message, 'fsockopen') === false && stripos($message, 'Name or service not known') === false)
	)
		{
		webServiceError('<h1>Unhandled Internal Error (<abbr title="Error Level">#</abbr>'.$errorLevel.')</h1><p>"'.$message.'"</p><p>In <tt>'.$file.'</tt> &nbsp; line: <tt>'.$line.'</tt></p>');
		}
	}

function webServiceError($message, $errorNumber = 500)
	{
	if(!headers_sent())
		{
		header('HTTP/1.1 '.$errorNumber);
		header('Status: '.$errorNumber);
		}
	$pageType = 'unknown';
	if(substr($errorNumber, 0, 1) == '2')
		{
		$title = 'OK';
		$pageType = 'good';
		}
	else
		{
		$title = 'Error';
		$pageType = 'bad';
		}
	if(!defined('DOCVERT_CLIENT_TYPE'))
		{
		die('Programming error: DOCVERT_CLIENT_TYPE has not been set. (error occured inside webServiceError() )');
		}
	switch(DOCVERT_CLIENT_TYPE)
		{
		case 'web':
			$head = '<style type="text/css">body{font-family:sans-serif;} h1{font-size:large;} h2{font-size:medium} h3{font-size:small} .windowTitle{color:white;margin:0px;padding:5px;font-size:small} .bad {background:#ffeeee; border:solid 2px red} .bad .windowTitle {background:red} .good {background:#eeffee;border: solid 2px #bbccbb} .good .windowTitle {background:#006600} .footer {margin-top:0px;padding:4px;font-size:small} .bad .footer {background:#ffcccc} .bad .footer .divider {color:#ffcccc} .good .footer {background:#ccffcc} .good .footer .divider {color:#ccffccc} .standardAdvice {margin:30px 0px 0px 0px; padding: 0px 0px 10px 15px;} </style>';
			$body = '<div class="'.$pageType.'">'."\n";
			$body .= '    <h1 class="windowTitle">Docvert: '.$title.' '.$errorNumber.'</h1>'."\n";
			$body .= '    <div style="padding:10px">'."\n";
			$body .= '	'.$message."\n";
			$body .= '    </div>'."\n";
			$body .= '    <p class="standardAdvice">&bull; Read <a href="doc/install.txt">install.txt</a> and <a href="doc/troubleshooting.txt">troubleshooting.txt</a> for general tips.</p>'."\n";
			$body .= '    <div class="footer"><a href="sample-use.php">Back...</a> &nbsp;<span class="divider">|</span>&nbsp; <a href="http://docvert.org/">Docvert Homepage On The Web</a></div>'."\n";
			$body .= '</div>'."\n";
			$template = getXHTMLTemplate();
			$template = str_replace('{{title}}', $title, $template);
			$template = str_replace('{{head}}', $head, $template);
			$template = str_replace('{{body}}', $body, $template);
			die($template);
			break;
		case 'command line':
			$endOfBlockElements = array('</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</li>', '</blockquote>');
			$message = str_replace($endOfBlockElements, "\n", $message);
			$message = preg_replace('/<.*?>/s','',$message);
			$message = str_replace('&lt;','<', $message);
			$message = str_replace('&gt;','>', $message);
			$message = str_replace('&amp;','&', $message);
			$message .= "\n";
			$message = trim($message)."\n";
			if($pageType == 'bad')
				{
				file_put_contents("php://stderr", $message);
				}
			else
				{
				print $message;
				}
			die();
			break;
		}
	}

function getXHTMLTemplate()
	{
	$template = null;
	$template .= '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">'."\n";
	$template .= '<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">'."\n";
	$template .= '<head>'."\n";
	$template .= '<title>{{title}}</title>'."\n";
	$template .= '{{head}}'."\n";
	$template .= '</head>'."\n";
	$template .= '<body>'."\n";
	$template .= '{{body}}'."\n";
	$template .= '</body>'."\n";
	$template .= '</html>';
	//whitespace to make the page over 4KB because IE won't display custom error messages unless they're larger than 4-5KB.
	for($whitespaceLoop = 0; $whitespaceLoop < 10; $whitespaceLoop++)
		{
		$template .= '															 '."\r\n";
		}
	return $template;
	}

/**
 * Removes XML declaration (or processing instructions) from an XML string.
 * @return string
*/
function removeXmlDeclaration($xmlString)
	{
	return preg_replace("/<\\?.*?\\?>/", "", $xmlString);
	}


/**
 * Removes Doctype from an XML string.
 * @return string
*/
function removeDoctype($xmlString)
	{
	return preg_replace("/<\!.*?>/sm", "", $xmlString);
	}

/**
 * Removes XML comments
 * @return string
*/
function removeXmlComments($xmlString)
	{
	return preg_replace("/<\!--.*?-->/sm", "", $xmlString);
	}


/**
 * Prints the xml string to the screen in such a way that Firefox will render it
 * as an XML tree (useful for debugging xml)
 * WARNING! To get Firefox to render it as an XML tree it must significantly mangle
 * the XML. Read the code and understand the replacements.
*/
function displayXmlString($xmlString, $withFilter=true)
	{
	if($withFilter)
		{
		if(substr(trim($xmlString), 0, 1) != '<')
			{
			$xmlString = file_get_contents($xmlString);
			}
		$xmlString = removeXmlDeclaration($xmlString);
		
		$xmlString = trim($xmlString);
		
		$xmlString = str_replace('xmlns', 'xmlnamespace', $xmlString);
		$xmlString = str_replace(':', '-colon-', $xmlString);
		$xmlString = str_replace('<!--', 'DOCVERT-XML-START-COMMENT', $xmlString);
		$xmlString = str_replace('-->', 'DOCVERT-XML-END-COMMENT', $xmlString);
		$xmlString = str_replace('--', '-hyphen-hyphen-', $xmlString);
		$xmlString = str_replace('DOCVERT-XML-START-COMMENT', '<!--', $xmlString);
		$xmlString = str_replace('DOCVERT-XML-END-COMMENT', '-->', $xmlString);
		$xmlString = preg_replace("/<!([^>]*?)>/si", "<!-- \\1 -->", $xmlString);		
		$xmlString =  characterEntityToNCR($xmlString);
		
		$xmlString = '<root>DOCVERT NOTE: This document has been changed in order to display as an XML tree. A root node has been added, colons have been changed to "-colon-" and XML namespaces (xmlns) have been changed to "xmlnamespace". More changes have been made, see ~core/lib.php and displayXmlString() for the specifics. '."\n".$xmlString."\n".'</root>';
		}
	header('Content-type: text/xml');
	die($xmlString);
	}

/**
 * Converts strings containing "&quot;" to "&34;" and so on.
 * Useful for XML processors which don't know HTMLs entities.
*/
function characterEntityToNCR($text)
	{
	$toNcr = array(
		'&quot;' => '&#34;',
		'&amp;' => '&#38;',
		'&frasl;' => '&#47;',
		'&lt;' => '&#60;',
		'&gt;' => '&#62;',
		'|' => '&#124;',
		'&nbsp;' => '&#160;',
		'&iexcl;' => '&#161;',
		'&cent;' => '&#162;',
		'&pound;' => '&#163;',
		'&curren;' => '&#164;',
		'&yen;' => '&#165;',
		'&brvbar;' => '&#166;',
		'&brkbar;' => '&#166;',
		'&sect;' => '&#167;',
		'&uml;' => '&#168;',
		'&die;' => '&#168;',
		'&copy;' => '&#169;',
		'&ordf;' => '&#170;',
		'&laquo;' => '&#171;',
		'&not;' => '&#172;',
		'&shy;' => '&#173;',
		'&reg;' => '&#174;',
		'&macr;' => '&#175;',
		'&hibar;' => '&#175;',
		'&deg;' => '&#176;',
		'&plusmn;' => '&#177;',
		'&sup2;' => '&#178;',
		'&sup3;' => '&#179;',
		'&acute;' => '&#180;',
		'&micro;' => '&#181;',
		'&para;' => '&#182;',
		'&middot;' => '&#183;',
		'&cedil;' => '&#184;',
		'&sup1;' => '&#185;',
		'&ordm;' => '&#186;',
		'&raquo;' => '&#187;',
		'&frac14;' => '&#188;',
		'&frac12;' => '&#189;',
		'&frac34;' => '&#190;',
		'&iquest;' => '&#191;',
		'&Agrave;' => '&#192;',
		'&Aacute;' => '&#193;',
		'&Acirc;' => '&#194;',
		'&Atilde;' => '&#195;',
		'&Auml;' => '&#196;',
		'&Aring;' => '&#197;',
		'&AElig;' => '&#198;',
		'&Ccedil;' => '&#199;',
		'&Egrave;' => '&#200;',
		'&Eacute;' => '&#201;',
		'&Ecirc;' => '&#202;',
		'&Euml;' => '&#203;',
		'&Igrave;' => '&#204;',
		'&Iacute;' => '&#205;',
		'&Icirc;' => '&#206;',
		'&Iuml;' => '&#207;',
		'&ETH;' => '&#208;',
		'&Ntilde;' => '&#209;',
		'&Ograve;' => '&#210;',
		'&Oacute;' => '&#211;',
		'&Ocirc;' => '&#212;',
		'&Otilde;' => '&#213;',
		'&Ouml;' => '&#214;',
		'&times;' => '&#215;',
		'&Oslash;' => '&#216;',
		'&Ugrave;' => '&#217;',
		'&Uacute;' => '&#218;',
		'&Ucirc;' => '&#219;',
		'&Uuml;' => '&#220;',
		'&Yacute;' => '&#221;',
		'&THORN;' => '&#222;',
		'&szlig;' => '&#223;',
		'&agrave;' => '&#224;',
		'&aacute;' => '&#225;',
		'&acirc;' => '&#226;',
		'&atilde;' => '&#227;',
		'&auml;' => '&#228;',
		'&aring;' => '&#229;',
		'&aelig;' => '&#230;',
		'&ccedil;' => '&#231;',
		'&egrave;' => '&#232;',
		'&eacute;' => '&#233;',
		'&ecirc;' => '&#234;',
		'&euml;' => '&#235;',
		'&igrave;' => '&#236;',
		'&iacute;' => '&#237;',
		'&icirc;' => '&#238;',
		'&iuml;' => '&#239;',
		'&eth;' => '&#240;',
		'&ntilde;' => '&#241;',
		'&ograve;' => '&#242;',
		'&oacute;' => '&#243;',
		'&ocirc;' => '&#244;',
		'&otilde;' => '&#245;',
		'&ouml;' => '&#246;',
		'&divide;' => '&#247;',
		'&oslash;' => '&#248;',
		'&ugrave;' => '&#249;',
		'&uacute;' => '&#250;',
		'&ucirc;' => '&#251;',
		'&uuml;' => '&#252;',
		'&yacute;' => '&#253;',
		'&thorn;' => '&#254;',
		'&yuml;' => '&#255;',
		'&OElig;' => '&#338;',
		'&oelig;' => '&#339;',
		'&Scaron;' => '&#352;',
		'&scaron;' => '&#353;',
		'&Yuml;' => '&#376;',
		'&fnof;' => '&#402;',
		'&circ;' => '&#710;',
		'&tilde;' => '&#732;',
		'&Alpha;' => '&#913;',
		'&Beta;' => '&#914;',
		'&Gamma;' => '&#915;',
		'&Delta;' => '&#916;',
		'&Epsilon;' => '&#917;',
		'&Zeta;' => '&#918;',
		'&Eta;' => '&#919;',
		'&Theta;' => '&#920;',
		'&Iota;' => '&#921;',
		'&Kappa;' => '&#922;',
		'&Lambda;' => '&#923;',
		'&Mu;' => '&#924;',
		'&Nu;' => '&#925;',
		'&Xi;' => '&#926;',
		'&Omicron;' => '&#927;',
		'&Pi;' => '&#928;',
		'&Rho;' => '&#929;',
		'&Sigma;' => '&#931;',
		'&Tau;' => '&#932;',
		'&Upsilon;' => '&#933;',
		'&Phi;' => '&#934;',
		'&Chi;' => '&#935;',
		'&Psi;' => '&#936;',
		'&Omega;' => '&#937;',
		'&alpha;' => '&#945;',
		'&beta;' => '&#946;',
		'&gamma;' => '&#947;',
		'&delta;' => '&#948;',
		'&epsilon;' => '&#949;',
		'&zeta;' => '&#950;',
		'&eta;' => '&#951;',
		'&theta;' => '&#952;',
		'&iota;' => '&#953;',
		'&kappa;' => '&#954;',
		'&lambda;' => '&#955;',
		'&mu;' => '&#956;',
		'&nu;' => '&#957;',
		'&xi;' => '&#958;',
		'&omicron;' => '&#959;',
		'&pi;' => '&#960;',
		'&rho;' => '&#961;',
		'&sigmaf;' => '&#962;',
		'&sigma;' => '&#963;',
		'&tau;' => '&#964;',
		'&upsilon;' => '&#965;',
		'&phi;' => '&#966;',
		'&chi;' => '&#967;',
		'&psi;' => '&#968;',
		'&omega;' => '&#969;',
		'&thetasym;' => '&#977;',
		'&upsih;' => '&#978;',
		'&piv;' => '&#982;',
		'&ensp;' => '&#8194;',
		'&emsp;' => '&#8195;',
		'&thinsp;' => '&#8201;',
		'&zwnj;' => '&#8204;',
		'&zwj;' => '&#8205;',
		'&lrm;' => '&#8206;',
		'&rlm;' => '&#8207;',
		'&ndash;' => '&#8211;',
		'&mdash;' => '&#8212;',
		'&lsquo;' => '&#8216;',
		'&rsquo;' => '&#8217;',
		'&sbquo;' => '&#8218;',
		'&ldquo;' => '&#8220;',
		'&rdquo;' => '&#8221;',
		'&bdquo;' => '&#8222;',
		'&dagger;' => '&#8224;',
		'&Dagger;' => '&#8225;',
		'&bull;' => '&#8226;',
		'&hellip;' => '&#8230;',
		'&permil;' => '&#8240;',
		'&prime;' => '&#8242;',
		'&Prime;' => '&#8243;',
		'&lsaquo;' => '&#8249;',
		'&rsaquo;' => '&#8250;',
		'&oline;' => '&#8254;',
		'&frasl;' => '&#8260;',
		'&euro;' => '&#8364;',
		'&image;' => '&#8465;',
		'&weierp;' => '&#8472;',
		'&real;' => '&#8476;',
		'&trade;' => '&#8482;',
		'&alefsym;' => '&#8501;',
		'&larr;' => '&#8592;',
		'&uarr;' => '&#8593;',
		'&rarr;' => '&#8594;',
		'&darr;' => '&#8595;',
		'&harr;' => '&#8596;',
		'&crarr;' => '&#8629;',
		'&lArr;' => '&#8656;',
		'&uArr;' => '&#8657;',
		'&rArr;' => '&#8658;',
		'&dArr;' => '&#8659;',
		'&hArr;' => '&#8660;',
		'&forall;' => '&#8704;',
		'&part;' => '&#8706;',
		'&exist;' => '&#8707;',
		'&empty;' => '&#8709;',
		'&nabla;' => '&#8711;',
		'&isin;' => '&#8712;',
		'&notin;' => '&#8713;',
		'&ni;' => '&#8715;',
		'&prod;' => '&#8719;',
		'&sum;' => '&#8721;',
		'&minus;' => '&#8722;',
		'&lowast;' => '&#8727;',
		'&radic;' => '&#8730;',
		'&prop;' => '&#8733;',
		'&infin;' => '&#8734;',
		'&ang;' => '&#8736;',
		'&and;' => '&#8743;',
		'&or;' => '&#8744;',
		'&cap;' => '&#8745;',
		'&cup;' => '&#8746;',
		'&int;' => '&#8747;',
		'&there4;' => '&#8756;',
		'&sim;' => '&#8764;',
		'&cong;' => '&#8773;',
		'&asymp;' => '&#8776;',
		'&ne;' => '&#8800;',
		'&equiv;' => '&#8801;',
		'&le;' => '&#8804;',
		'&ge;' => '&#8805;',
		'&sub;' => '&#8834;',
		'&sup;' => '&#8835;',
		'&nsub;' => '&#8836;',
		'&sube;' => '&#8838;',
		'&supe;' => '&#8839;',
		'&oplus;' => '&#8853;',
		'&otimes;' => '&#8855;',
		'&perp;' => '&#8869;',
		'&sdot;' => '&#8901;',
		'&lceil;' => '&#8968;',
		'&rceil;' => '&#8969;',
		'&lfloor;' => '&#8970;',
		'&rfloor;' => '&#8971;',
		'&lang;' => '&#9001;',
		'&rang;' => '&#9002;',
		'&loz;' => '&#9674;',
		'&spades;' => '&#9824;',
		'&clubs;' => '&#9827;',
		'&hearts;' => '&#9829;',
		'&diams;' => '&#9830;'
		);
	foreach ($toNcr as $entity => $ncr)
		{
		$text = str_replace($entity, $ncr, $text);
		}
	return $text;
	}




function resolveRelativeUrl($relativeUrl)
	{
	//print $relativeUrl.'<br />';
	$urlParts = explode('/', $relativeUrl);
	$currentUrl=array();
	for($i=0; $i < count($urlParts); $i++)
		{
		
		if($urlParts[$i] == '..')
			{
			array_pop($currentUrl);
			//print '['.implode('/', $currentUrl).'] (Pop!)';
			}
		else if($urlParts[$i] == '.')
			{
			}
		else
			{
			//print "Adding ".$urlParts[$i].' = ';
			$currentUrl[] = $urlParts[$i];
			//print implode('/', $currentUrl);
			}
		//print '<br />';
		}
	return implode('/', $currentUrl);
	}


/* Returns the connection part of a URL (eg, from http://example.com:80/fragmasterbowen
 * it would return http://example.com:80/
 */
function getUrlConnectionPart($url)
	{
	$originalUrlParts = parse_url($url);
	if(!isset($originalUrlParts['scheme']))
		{
		webServiceError('Not a valid URL either because<ol><li>it has no protocol (it didn\'t start with "http://")</li><li>The domain part was incomplete or malformed</li></ol>');
		}

	$websiteBase = $originalUrlParts['scheme'].'://';
	if(isset($originalUrlParts['username']))
		{
		$websiteBase .= $originalUrlParts['username'];
		}
	if(isset($originalUrlParts['password']))
		{
		$websiteBase .= ':'.$originalUrlParts['password'];
		}
	if(isset($originalUrlParts['username']) && isset($originalUrlParts['password']))
		{
		$websiteBase .= '@';
		}
	$websiteBase .= $originalUrlParts['host'];
	if(isset($originalUrlParts['port']))
		{
		$websiteBase .= ':'.$originalUrlParts['port'];
		}
	return $websiteBase;
	}

function getUrlDomainAndPortPart($url)
	{
	$originalUrlParts = parse_url($url);
	$port = 80;
	if(isset($originalUrlParts['port']))
		{
		$port = $originalUrlParts['port'];
		}
	if(!isset($originalUrlParts['host']))
		{
		webServiceError('Error in getUrlDomainAndPortPart() accessing "'.$url.'". Url parts were '.print_r($originalUrlParts, true).' . Backtrace was '.nl2br(print_r(debug_backtrace(), true)));
		}
	return Array($originalUrlParts['host'], $port);
	}

function getUrlLocalPart($url)
	{
	$connectionPart = getUrlConnectionPart($url);
	return substr($url, strlen($connectionPart));
	}

function getUrlLocalPartDirectory($url)
	{
	$url = getUrlLocalPart($url);
	if(containsString($url, '?'))
		{
		$url = substringBefore($url, '?');
		}
	return substr($url, 0, strrpos($url, '/')+1);
	}

function generateDocument($pages, $generatorPipeline)
	{
	if(preg_match('/.\\//s', $generatorPipeline))
		{
		webServiceError('The generatorPipeline contained disallowed characters (either a slash or a dot)');
		}
	
	$userAgent = 'Mozilla/5.0 (X11; U; Linux i686; en-US; rv:bignumber) Docvert';
	$httpContextOptions = array('http'=> array('header'=>'User-Agent: '.$userAgent));
	$httpContext = stream_context_create($httpContextOptions);

	$docvertDir = dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR;
	$docvertWritableDir = $docvertDir.'writable'.DIRECTORY_SEPARATOR;
	$disallowDocumentGeneration = getConfigItem('doNotAllowDocumentGeneration');
	if($disallowDocumentGeneration == 'true')
		{
		webServiceError('Document Generation is currently disabled on this install of Docvert.');
		}

	$pageXml = '<c:document xmlns="http://www.w3.org/1999/xhtml" xmlns:c="container">'."\n";
	$pageTemplate = "\n\t".'<c:page url="{{url}}" {{baseUrl}}>{{page}}</c:page>'."\n";
	$config = array(
		'indent' => true,
		'output-xhtml' => true,
		'wrap' => 200);

	if(!class_exists('tidy'))
		{
		webServiceError('Generating webpages requires the PHP module "htmltidy". It is not currently installed. See the <a href="doc/install.txt">install.txt</a> for software requirements.');
		}

	$tidy = new tidy;

	$baseTagPattern = "/<base[^>]*?href=([^>]*?)>/is";;

	foreach($pages as $page)
		{
		if(trim($page) != '' && (stringStartsWith($page, 'http://') || stringStartsWith($page, 'https://')))
			{
			$pageHtml = file_get_contents($page, null, $httpContext);
			$tidy->parseString($pageHtml, $config, 'utf8');
			$tidy->cleanRepair();
			$thisPage = str_replace('{{url}}', $page, $pageTemplate);
			$baseUrl = ''; //supporting that ugly old hack of <base>
			preg_match($baseTagPattern, $pageHtml, $matches);
			if(count($matches) > 0)
				{
				$baseUrl = 'baseUrl="'.substr($matches[1], 1, -2).'"';
				}
			$thisPage = str_replace('{{baseUrl}}', $baseUrl, $thisPage);
			$tidiedPageContents = characterEntityToNCR(removeDoctype(removeXmlComments($tidy)));
			$styleTagPattern = "/<style.*?<\/style>/is";
			$tidiedPageContents = preg_replace($styleTagPattern, '', $tidiedPageContents);
			$scriptTagPattern = "/<script.*?<\/script>/is";
			$tidiedPageContents = preg_replace($scriptTagPattern, '', $tidiedPageContents);
			$questionMarkPattern = "/<\\?.*?\\?>/is"; //as strangely used on news.yahoo.com
			$tidiedPageContents = preg_replace($questionMarkPattern, '', $tidiedPageContents);

			$thisPage = str_replace('{{page}}', $tidiedPageContents, $thisPage);
			$pageXml .= $thisPage;
			}
		}
	$pageXml .= '</c:document>';

	$temporaryDirectory = getTemporaryDirectory();
	$pipelineDirectory = dirname(dirname(__file__)).DIRECTORY_SEPARATOR.'generator-pipeline'.DIRECTORY_SEPARATOR.$generatorPipeline.DIRECTORY_SEPARATOR;
	$pipelinePath = $pipelineDirectory.'pipeline.xml';
	if(!file_exists($pipelinePath))
		{
		webServiceError('Generation pipeline not found at '.$pipelinePath);
		}
	$pipelineString = file_get_contents($pipelinePath);
	$pipelineString = substr($pipelineString, strpos($pipelineString, '<pipeline>') + 10);
	$pipelineString = substr($pipelineString, 0, strpos($pipelineString, '</pipeline>'));
	$pipelineStages = xmlStringToArray($pipelineString);
	$pipelineSettings = array("pipeline" => $generatorPipeline, "autopipeline" => $generatorPipeline);
	processAPipelineLevel($pipelineStages, $pageXml, $pipelineDirectory, $temporaryDirectory, $temporaryDirectory, $pipelineSettings);	
	$openDocumentPath = $temporaryDirectory.'output.odt';
	zipFiles($temporaryDirectory, $openDocumentPath);
	header('Content-disposition: attachment; filename='.basename($openDocumentPath));
	header('Content-type: application/vnd.oasis.opendocument.text');
	readfile($openDocumentPath);
	}

?>
