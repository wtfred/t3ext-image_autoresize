<?php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with TYPO3 source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Causal\ImageAutoresize\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Configuration controller.
 *
 * @package     TYPO3
 * @subpackage  tx_imageautoresize
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class ConfigurationController
{

    const virtualTable = 'tx_imageautoresize';
    const virtualRecordId = 1;

    /**
     * @var string
     */
    protected $extKey = 'image_autoresize';

    /**
     * @var array
     */
    protected $expertKey = 'image_autoresize_ff';

    /**
     * @var \TYPO3\CMS\Lang\LanguageService
     */
    protected $languageService;

    /**
     * @var \TYPO3\CMS\Backend\Form\FormEngine
     */
    protected $tceforms;

    /**
     * @var \TYPO3\CMS\Backend\Form\FormResultCompiler $formResultCompiler
     */
    protected $formResultCompiler;

    /**
     * @var \TYPO3\CMS\Backend\Template\ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * @var array
     */
    protected $config;

    /**
     * Generally used for accumulating the output content of backend modules
     *
     * @var string
     */
    public $content = '';

    /**
     * Default constructor
     */
    public function __construct()
    {
        $this->moduleTemplate = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Template\ModuleTemplate::class);
        $this->languageService = $GLOBALS['LANG'];

        if (!empty($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->expertKey])) {
            // Automatically migrate configuration from v1.8
            $config = $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->expertKey];
            $config = unserialize($config);
            if (is_array($config) && !empty($config) && $this->persistConfiguration($config)) {
                // Drop legacy configuration
                $this->writeToLocalconf($this->expertKey, []);
            }
        }
        $this->config = static::readConfiguration();
        $this->config['conversion_mapping'] = implode(LF, explode(',', $this->config['conversion_mapping']));
    }

    /**
     * Injects the request object for the current request or subrequest
     * As this controller goes only through the main() method, it is rather simple for now
     *
     * @param ServerRequestInterface $request the current request
     * @return ResponseInterface the response with the content
     */
    public function mainAction(ServerRequestInterface $request) : ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = func_num_args() === 2 ? func_get_arg(1) : null;

        $this->languageService->includeLLFile('EXT:image_autoresize/Resources/Private/Language/locallang_mod.xlf');
        $this->processData();

        $formTag = '<form action="" method="post" name="editform" id="EditDocumentController">';

        $this->moduleTemplate->setForm($formTag);

        $this->content .= sprintf('<h3>%s</h3>', htmlspecialchars($this->languageService->getLL('title')));
        $this->addStatisticsAndSocialLink();

        // Generate the content
        $this->moduleContent($this->config);

        // Compile document
        $this->addToolbarButtons();
        $this->moduleTemplate->setContent($this->content);
        $content = $this->moduleTemplate->renderContent();

        if ($response !== null) {
            $response->getBody()->write($content);
        } else {
            // Behaviour in TYPO3 v9
            $response = new HtmlResponse($content);
        }

        return $response;
    }

    /**
     * Generates the module content.
     *
     * @param array $row
     * @return void
     */
    protected function moduleContent(array $row)
    {
        $this->formResultCompiler = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\FormResultCompiler::class);

        $wizard = $this->formResultCompiler->addCssFiles();
        $wizard .= $this->buildForm($row);
        $wizard .= $this->formResultCompiler->printNeededJSFunctions();

        $this->content .= $wizard;
    }

    /**
     * Builds the expert configuration form.
     *
     * @param array $row
     * @return string
     */
    protected function buildForm(array $row)
    {
        $record = [
            'uid' => static::virtualRecordId,
            'pid' => 0,
        ];
        $record = array_merge($record, $row);

        // Trick to use a virtual record
        $dataProviders =& $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'];

        $dataProviders[\Causal\ImageAutoresize\Backend\Form\FormDataProvider\VirtualDatabaseEditRow::class] = [
            'before' => [
                \TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseEditRow::class,
            ]
        ];

        // Initialize record in our virtual provider
        \Causal\ImageAutoresize\Backend\Form\FormDataProvider\VirtualDatabaseEditRow::initialize($record);

        /** @var \TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord $formDataGroup */
        $formDataGroup = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord::class);
        /** @var \TYPO3\CMS\Backend\Form\FormDataCompiler $formDataCompiler */
        $formDataCompiler = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\FormDataCompiler::class, $formDataGroup);
        /** @var \TYPO3\CMS\Backend\Form\NodeFactory $nodeFactory */
        $nodeFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\NodeFactory::class);

        $formDataCompilerInput = [
            'tableName' => static::virtualTable,
            'vanillaUid' => $record['uid'],
            'command' => 'edit',
            'returnUrl' => '',
        ];

        // Load the configuration of virtual table 'tx_imageautoresize'
        $this->loadVirtualTca();

        $formData = $formDataCompiler->compile($formDataCompilerInput);
        $formData['renderType'] = 'outerWrapContainer';
        $formResult = $nodeFactory->create($formData)->render();

        // Remove header and footer
        $html = preg_replace('/<h1>.*<\/h1>/', '', $formResult['html']);

        $startFooter = strrpos($html, '<div class="help-block text-right">');
        $endTag = '</div>';

        if ($startFooter !== false) {
            $endFooter = strpos($html, $endTag, $startFooter);
            $html = substr($html, 0, $startFooter) . substr($html, $endFooter + strlen($endTag));
        }

        $formResult['html'] = '';
        $formResult['doSaveFieldName'] = 'doSave';

        // @todo: Put all the stuff into FormEngine as final "compiler" class
        // @todo: This is done here for now to not rewrite JStop()
        // @todo: and printNeededJSFunctions() now
        $this->formResultCompiler->mergeResult($formResult);

        // Combine it all
        $formContent = '
			<!-- EDITING FORM -->
			' . $html . '

			<input type="hidden" name="returnUrl" value="' . htmlspecialchars($this->retUrl) . '" />
			<input type="hidden" name="closeDoc" value="0" />
			<input type="hidden" name="doSave" value="0" />
			<input type="hidden" name="_serialNumber" value="' . md5(microtime()) . '" />
			<input type="hidden" name="_scrollPosition" value="" />';

        $overriddenAjaxUrl = GeneralUtility::quoteJSvalue(BackendUtility::getModuleUrl('TxImageAutoresize::record_flex_container_add'));
        $formContent .= <<<HTML
<script type="text/javascript">
    TYPO3.settings.ajaxUrls['record_flex_container_add'] = $overriddenAjaxUrl;
</script>
HTML;

        return $formContent;
    }

    /**
     * Creates the toolbar buttons.
     *
     * @return void
     */
    protected function addToolbarButtons()
    {
        // Render SAVE type buttons:
        // The action of each button is decided by its name attribute. (See doProcessData())
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $saveSplitButton = $buttonBar->makeSplitButton();

        // SAVE button:
        $saveButton = $buttonBar->makeInputButton()
            ->setTitle(htmlspecialchars($this->languageService->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:rm.saveDoc')))
            ->setName('_savedok')
            ->setValue('1')
            ->setForm('EditDocumentController')
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon(
                'actions-document-save',
                \TYPO3\CMS\Core\Imaging\Icon::SIZE_SMALL
            ));
        $saveSplitButton->addItem($saveButton, true);

        // SAVE & CLOSE button:
        $saveAndCloseButton = $buttonBar->makeInputButton()
            ->setTitle(htmlspecialchars($this->languageService->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:rm.saveCloseDoc')))
            ->setName('_saveandclosedok')
            ->setValue('1')
            ->setForm('EditDocumentController')
            ->setClasses('t3js-editform-submitButton')
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon(
                'actions-document-save-close',
                \TYPO3\CMS\Core\Imaging\Icon::SIZE_SMALL
            ));
        $saveSplitButton->addItem($saveAndCloseButton);

        $buttonBar->addButton($saveSplitButton, \TYPO3\CMS\Backend\Template\Components\ButtonBar::BUTTON_POSITION_LEFT, 2);

        // CLOSE button:
        $closeButton = $buttonBar->makeLinkButton()
            ->setTitle(htmlspecialchars($this->languageService->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:rm.closeDoc')))
            ->setHref('#')
            ->setClasses('t3js-editform-close')
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon(
                'actions-view-go-back',
                \TYPO3\CMS\Core\Imaging\Icon::SIZE_SMALL
            ));
        $buttonBar->addButton($closeButton);
    }

    /**
     * Prints out the module HTML.
     *
     * @return string HTML output
     */
    public function printContent()
    {
        echo $this->content;
    }

    /**
     * Returns the default configuration.
     *
     * @return array
     */
    protected static function getDefaultConfiguration()
    {
        return [
            'directories' => 'fileadmin/,uploads/',
            'file_types' => 'jpg,jpeg,png',
            'threshold' => '400K',
            'max_width' => '1024',
            'max_height' => '768',
            'max_size' => '100M',
            'auto_orient' => true,
            'conversion_mapping' => implode(',', [
                'ai => jpg',
                'bmp => jpg',
                'pcx => jpg',
                'tga => jpg',
                'tif => jpg',
                'tiff => jpg',
            ]),
        ];
    }

    /**
     * Processes submitted data and stores it to localconf.php.
     *
     * @return void
     */
    protected function processData()
    {
        $close = GeneralUtility::_GP('closeDoc');
        $save = GeneralUtility::_GP('_savedok');
        $saveAndClose = GeneralUtility::_GP('_saveandclosedok');

        if ($save || $saveAndClose) {
            $table = static::virtualTable;
            $id = static::virtualRecordId;
            $field = 'rulesets';

            $inputData_tmp = GeneralUtility::_GP('data');
            $data = $inputData_tmp[$table][$id];

            if (count($inputData_tmp[$table]) > 1) {
                foreach ($inputData_tmp[$table] as $key => $values) {
                    if ($key === $id) continue;
                    ArrayUtility::mergeRecursiveWithOverrule($data, $values);
                }
            }

            $newConfig = $this->config;
            ArrayUtility::mergeRecursiveWithOverrule($newConfig, $data);

            // Action commands (sorting order and removals of FlexForm elements)
            $ffValue = &$data[$field];
            if ($ffValue) {
                $actionCMDs = GeneralUtility::_GP('_ACTION_FLEX_FORMdata');
                if (is_array($actionCMDs[$table][$id][$field]['data'])) {
                    $dataHandler = new CustomDataHandler();
                    $dataHandler->_ACTION_FLEX_FORMdata($ffValue['data'], $actionCMDs[$table][$id][$field]['data']);
                }
                // Renumber all FlexForm temporary ids
                $this->persistFlexForm($ffValue['data']);

                // Keep order of FlexForm elements
                $newConfig[$field] = $ffValue;
            }

            // Persist configuration
            $localconfConfig = $newConfig;
            $localconfConfig['conversion_mapping'] = implode(',', GeneralUtility::trimExplode(LF, $localconfConfig['conversion_mapping'], true));

            if ($this->persistConfiguration($localconfConfig)) {
                $this->config = $newConfig;
            }
        }

        if ($close || $saveAndClose) {
            $closeUrl = BackendUtility::getModuleUrl('tools_ExtensionmanagerExtensionmanager');
            \TYPO3\CMS\Core\Utility\HttpUtility::redirect($closeUrl);
        }
    }

    /**
     * Writes a configuration line to AdditionalConfiguration.php.
     * We don't use the <code>tx_install</code> methods as they add unneeded
     * comments at the end of the file.
     *
     * @param string $key
     * @param array $config
     * @return bool
     */
    protected function writeToLocalconf($key, array $config)
    {
        /** @var $objectManager \TYPO3\CMS\Extbase\Object\ObjectManager */
        $objectManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
        /** @var $configurationManager \TYPO3\CMS\Core\Configuration\ConfigurationManager */
        $configurationManager = $objectManager->get(\TYPO3\CMS\Core\Configuration\ConfigurationManager::class);
        return $configurationManager->setLocalConfigurationValueByPath('EXT/extConf/' . $key, serialize($config));
    }


    /**
     * @return array
     */
    public static function readConfiguration() : array
    {
        $configurationFileName = PATH_site . 'typo3conf/image_autoresize.config.php';

        $config = file_exists($configurationFileName) ? include($configurationFileName) : [];
        if (!is_array($config) || empty($config)) {
            $config = static::getDefaultConfiguration();
            // Merge with eventual hook configuration
            if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['image_autoresize']['defaultConfiguration'])) {
                $config = array_merge($config, $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['image_autoresize']['defaultConfiguration']);
            }
        }

        return $config;
    }

    /**
     * Writes configuration to typo3conf/image_autoresize.config.php.
     *
     * @param array $config
     * @return bool
     */
    protected function persistConfiguration(array $config) : bool
    {
        $configurationFileName = PATH_site . 'typo3conf/image_autoresize.config.php';

        $exportConfig = var_export($config, true);
        $exportConfig = str_replace('array (', '[', $exportConfig);
        if (substr($exportConfig, -1) === ')') {
            $exportConfig = substr($exportConfig, 0, strlen($exportConfig) - 1) . ']';
        }
        $exportConfig = preg_replace('/=>\\s*[[]/s', '=> [', $exportConfig);
        $lines = explode(LF, $exportConfig);
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\\s+)(.+)$/', $line, $matches)) {
                if ($matches[2] === '),') {
                    // Convert ending of former array declaration to new syntax
                    $matches[2] = '],';
                }
                $lines[$i] = str_repeat(' ', 2 * strlen($matches[1])) . $matches[2];
            }
        }
        $exportConfig = implode(LF, $lines);

        $content = '<?' . 'php' . LF . 'return ' . $exportConfig . ';' . LF;
        $success = GeneralUtility::writeFile($configurationFileName, $content);
        return true;
    }

    /**
     * Loads the configuration of the virtual table 'tx_imageautoresize'.
     *
     * @return void
     */
    protected function loadVirtualTca()
    {
        $GLOBALS['TCA'][static::virtualTable] = include(ExtensionManagementUtility::extPath($this->extKey) . 'Configuration/TCA/Module/Options.php');
        ExtensionManagementUtility::addLLrefForTCAdescr(static::virtualTable, 'EXT:' . $this->extKey . '/Resource/Private/Language/locallang_csh_' . static::virtualTable . '.xlf');
    }

    /**
     * Persists FlexForm items by removing 'ID-' in front of new
     * items.
     *
     * @param array &$valueArray : by reference
     * @return void
     */
    protected function persistFlexForm(array &$valueArray)
    {
        foreach ($valueArray as $key => $value) {
            if ($key === 'el') {
                foreach ($value as $idx => $v) {
                    if ($v && substr($idx, 0, 3) === 'ID-') {
                        $valueArray[$key][substr($idx, 3)] = $v;
                        unset($valueArray[$key][$idx]);
                    }
                }
            } elseif (isset($valueArray[$key])) {
                $this->persistFlexForm($valueArray[$key]);
            }
        }
    }

    /**
     * Returns some statistics and a social link to Twitter.
     *
     * @return void
     */
    protected function addStatisticsAndSocialLink()
    {
        $fileName = PATH_site . 'typo3temp/.tx_imageautoresize';

        if (!is_file($fileName)) {
            return;
        }

        $data = json_decode(file_get_contents($fileName), true);
        if (!is_array($data) || !(isset($data['images']) && isset($data['bytes']))) {
            return;
        }

        $resourcesPath = '../' . ExtensionManagementUtility::siteRelPath($this->extKey) . 'Resources/Public/';
        $pageRenderer = $this->moduleTemplate->getPageRenderer();
        $pageRenderer->addCssFile($resourcesPath . 'Css/twitter.css');
        $pageRenderer->addJsFile($resourcesPath . 'JavaScript/popup.js');

        $totalSpaceClaimed = GeneralUtility::formatSize((int)$data['bytes']);
        $messagePattern = $this->languageService->getLL('storage.claimed');
        $message = sprintf($messagePattern, $totalSpaceClaimed, (int)$data['images']);

        $flashMessage = htmlspecialchars($message);

        $twitterMessagePattern = $this->languageService->getLL('social.twitter');
        $message = sprintf($twitterMessagePattern, $totalSpaceClaimed);
        $url = 'https://extensions.typo3.org/extension/image_autoresize/';

        $twitterLink = 'https://twitter.com/intent/tweet?text=' . urlencode($message) . '&url=' . urlencode($url);
        $twitterLink = GeneralUtility::quoteJSvalue($twitterLink);
        $flashMessage .= '
            <div class="custom-tweet-button">
                <a href="#" onclick="popitup(' . $twitterLink . ',\'twitter\')" title="' . htmlspecialchars($this->languageService->getLL('social.share')) . '">
                    <i class="btn-icon"></i>
                    <span class="btn-text">Tweet</span>
                </a>
            </div>';

        $this->content .= '
            <div class="alert alert-info">
                <div class="media">
                    <div class="media-left">
                        <span class="fa-stack fa-lg">
                            <i class="fa fa-circle fa-stack-2x"></i>
                            <i class="fa fa-info fa-stack-1x"></i>
                        </span>
                    </div>
                    <div class="media-body">
                        ' . $flashMessage . '
                    </div>
                </div>
            </div>
        ';
    }
}

// ReflectionMethod does not work properly with arguments passed as reference thus
// using a trick here
class CustomDataHandler extends \TYPO3\CMS\Core\DataHandling\DataHandler
{

    /**
     * Actions for flex form element (move, delete)
     * allows to remove and move flexform sections
     *
     * @param array &$valueArray by reference
     * @param array $actionCMDs
     * @return void
     */
    public function _ACTION_FLEX_FORMdata(&$valueArray, $actionCMDs)
    {
        parent::_ACTION_FLEX_FORMdata($valueArray, $actionCMDs);
    }

}
