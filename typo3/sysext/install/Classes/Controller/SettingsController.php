<?php
declare(strict_types=1);
namespace TYPO3\CMS\Install\Controller;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\FormProtection\InstallToolFormProtection;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Install\Configuration\FeatureManager;
use TYPO3\CMS\Install\Service\LocalConfigurationValueService;
use TYPO3\CMS\Saltedpasswords\Salt\SaltFactory;

/**
 * Settings controller
 */
class SettingsController extends AbstractController
{
    /**
     * Main "show the cards" view
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function cardsAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->initializeStandaloneView($request, 'Settings/Cards.html');
        $formProtection = FormProtectionFactory::get(InstallToolFormProtection::class);
        $view->assignMultiple([
            'changeInstallToolPasswordToken' => $formProtection->generateToken('installTool', 'changeInstallToolPassword'),
            'presetGetContentToken' => $formProtection->generateToken('installTool', 'presetsGetContent'),
            'systemMaintainerWriteToken' => $formProtection->generateToken('installTool', 'systemMaintainerWrite'),
            'systemMaintainerIsDevelopmentContext' => GeneralUtility::getApplicationContext()->isDevelopment(),
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
        ]);
    }

    /**
     * Change install tool password
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function changeInstallToolPasswordAction(ServerRequestInterface $request): ResponseInterface
    {
        $password = $request->getParsedBody()['install']['password'] ?? '';
        $passwordCheck = $request->getParsedBody()['install']['passwordCheck'];
        $messageQueue = new FlashMessageQueue('install');

        if ($password !== $passwordCheck) {
            $messageQueue->enqueue(new FlashMessage(
                'Install tool password not changed. Given passwords do not match.',
                '',
                FlashMessage::ERROR
            ));
        } elseif (strlen($password) < 8) {
            $messageQueue->enqueue(new FlashMessage(
                'Install tool password not changed. Given password must be at least eight characters long.',
                '',
                FlashMessage::ERROR
            ));
        } else {
            $saltFactory = SaltFactory::getSaltingInstance(null, 'BE');
            $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
            $configurationManager->setLocalConfigurationValueByPath(
                'BE/installToolPassword',
                $saltFactory->getHashedPassword($password)
            );
            $messageQueue->enqueue(new FlashMessage('Install tool password changed'));
        }
        return new JsonResponse([
            'success' => true,
            'status' => $messageQueue,
        ]);
    }

    /**
     * Return a list of possible and active system maintainers
     *
     * @return ResponseInterface
     */
    public function systemMaintainerGetListAction(): ResponseInterface
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        // We have to respect the enable fields here by our own because no TCA is loaded
        $queryBuilder = $connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $users = $queryBuilder
            ->select('uid', 'username', 'disable', 'starttime', 'endtime')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('admin', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->neq('username', $queryBuilder->createNamedParameter('_cli_', \PDO::PARAM_STR))
                )
            )
            ->orderBy('uid')
            ->execute()
            ->fetchAll();

        $systemMaintainerList = $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemMaintainers'] ?? [];
        $systemMaintainerList = array_map('intval', $systemMaintainerList);
        $currentTime = time();
        foreach ($users as &$user) {
            $user['disable'] = $user['disable'] ||
                ((int)$user['starttime'] !== 0 && $user['starttime'] > $currentTime) ||
                ((int)$user['endtime'] !== 0 && $user['endtime'] < $currentTime);
            $user['isSystemMaintainer'] = in_array((int)$user['uid'], $systemMaintainerList, true);
        }
        return new JsonResponse([
            'success' => true,
            'status' => [],
            'users' => $users,
        ]);
    }

    /**
     * Write new system maintainer list
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function systemMaintainerWriteAction(ServerRequestInterface $request): ResponseInterface
    {
        // Sanitize given user list and write out
        $newUserList = [];
        $users = $request->getParsedBody()['install']['users'] ?? [];
        if (is_array($users)) {
            foreach ($users as $uid) {
                if (MathUtility::canBeInterpretedAsInteger($uid)) {
                    $newUserList[] = (int)$uid;
                }
            }
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        $validatedUserList = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('admin', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($newUserList, Connection::PARAM_INT_ARRAY))
                )
            )->execute()->fetchAll();

        $validatedUserList = array_column($validatedUserList, 'uid');
        $validatedUserList = array_map('intval', $validatedUserList);

        $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
        $configurationManager->setLocalConfigurationValuesByPathValuePairs(
            [ 'SYS/systemMaintainers' => $validatedUserList ]
        );

        $messages = [];
        if (empty($validatedUserList)) {
            $messages[] = new FlashMessage(
                '',
                'Set system maintainer list to an empty array',
                FlashMessage::INFO
            );
        } else {
            $messages[] = new FlashMessage(
                implode(', ', $validatedUserList),
                'New system maintainer uid list',
                FlashMessage::INFO
            );
        }
        return new JsonResponse([
            'success' => true,
            'status' => $messages
        ]);
    }

    /**
     * Main LocalConfiguration card content
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function localConfigurationGetContentAction(ServerRequestInterface $request): ResponseInterface
    {
        $localConfigurationValueService = new LocalConfigurationValueService();
        $formProtection = FormProtectionFactory::get(InstallToolFormProtection::class);
        $view = $this->initializeStandaloneView($request, 'Settings/LocalConfigurationGetContent.html');
        $view->assignMultiple([
            'localConfigurationWriteToken' => $formProtection->generateToken('installTool', 'localConfigurationWrite'),
            'localConfigurationSectionNames' => $localConfigurationValueService->getSpeakingSectionNames(),
            'localConfigurationData' => $localConfigurationValueService->getCurrentConfigurationData(),
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
        ]);
    }

    /**
     * Write given LocalConfiguration settings
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function localConfigurationWriteAction(ServerRequestInterface $request): ResponseInterface
    {
        $settings = $request->getParsedBody()['install']['configurationValues'];
        if (!is_array($settings) || empty($settings)) {
            throw new \RuntimeException(
                'Expected value array not found',
                1502282283
            );
        }
        $localConfigurationValueService = new LocalConfigurationValueService();
        $messageQueue = $localConfigurationValueService->updateLocalConfigurationValues($settings);
        if (empty($messageQueue)) {
            $messageQueue->enqueue(new FlashMessage(
                '',
                'No values changed',
                FlashMessage::WARNING
            ));
        }
        return new JsonResponse([
            'success' => true,
            'status' => $messageQueue,
        ]);
    }

    /**
     * Main preset card content
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function presetsGetContentAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->initializeStandaloneView($request, 'Settings/PresetsGetContent.html');
        $presetFeatures = GeneralUtility::makeInstance(FeatureManager::class);
        $presetFeatures = $presetFeatures->getInitializedFeatures($request->getParsedBody()['install']['values'] ?? []);
        $formProtection = FormProtectionFactory::get(InstallToolFormProtection::class);
        $view->assignMultiple([
            'presetsActivateToken' => $formProtection->generateToken('installTool', 'presetsActivate'),
            'presetFeatures' => $presetFeatures,
        ]);
        return new JsonResponse([
            'success' => true,
            'html' => $view->render(),
        ]);
    }

    /**
     * Write selected presets
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function presetsActivateAction(ServerRequestInterface $request): ResponseInterface
    {
        $messages = new FlashMessageQueue('install');
        $configurationManager = new ConfigurationManager();
        $featureManager = new FeatureManager();
        $configurationValues = $featureManager->getConfigurationForSelectedFeaturePresets($request->getParsedBody()['install']['values'] ?? []);
        if (!empty($configurationValues)) {
            $configurationManager->setLocalConfigurationValuesByPathValuePairs($configurationValues);
            $messageBody = [];
            foreach ($configurationValues as $configurationKey => $configurationValue) {
                $messageBody[] = '\'' . $configurationKey . '\' => \'' . $configurationValue . '\'';
            }
            $messages->enqueue(new FlashMessage(
                implode('<br>', $messageBody),
                'Configuration written'
            ));
        } else {
            $messages->enqueue(new FlashMessage(
                '',
                'No configuration change selected',
                FlashMessage::INFO
            ));
        }
        return new JsonResponse([
            'success' => true,
            'status' => $messages,
        ]);
    }
}
