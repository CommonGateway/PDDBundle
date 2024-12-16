<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Mapping;
use App\Entity\Endpoint;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\ValidationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\WOOBundle\Service\FileService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Exception;

/**
 * Service responsible for synchronizing xxllnc cases to woo objects.
 *
 * @author
 * @license
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class SyncXxllncCasesService
{

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SymfonyStyle|null
     */
    private ?SymfonyStyle $style = null;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;

    /**
     * @var ValidationService $validationService.
     */
    private ValidationService $validationService;

    /**
     * @var CacheService $cacheService.
     */
    private CacheService $cacheService;

    /**
     * @var FileService $fileService.
     */
    private FileService $fileService;

    /**
     * @var WooService
     */
    private WooService $wooService;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * SyncXxllncCasesService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService            $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService         $mappingService
     * @param LoggerInterface        $pluginLogger
     * @param ValidationService      $validationService
     * @param FileService            $fileService
     * @param CacheService           $cacheService
     * @param WooService             $wooService
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CallService $callService,
        SynchronizationService $syncService,
        EntityManagerInterface $entityManager,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        ValidationService $validationService,
        FileService $fileService,
        CacheService $cacheService,
        WooService $wooService
    ) {
        $this->resourceService   = $resourceService;
        $this->callService       = $callService;
        $this->syncService       = $syncService;
        $this->entityManager     = $entityManager;
        $this->mappingService    = $mappingService;
        $this->logger            = $pluginLogger;
        $this->validationService = $validationService;
        $this->fileService       = $fileService;
        $this->cacheService      = $cacheService;
        $this->wooService        = $wooService;

    }//end __construct()


    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style
     *
     * @return self
     *
     * @todo change to monolog
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;

        return $this;

    }//end setStyle()


    /**
     * Retrieves files at the zaaksystem and maps them to bijlagen.
     *
     * @param array $result       The result data that contains the information of file fields.
     * @param array $documentMeta Metadata about a document, including the id.
     * @param array $config       Gateway config objects.
     *
     * @return array|null The view urls for files.
     */
    private function retrieveFile(array $result, array $documentMeta, array $config): ?array
    {
        // There can be expected here that there always should be a Bijlage ObjectEntity because of the mapping and hydration + flush that gets executed before this function.
        // ^ Note: this is necessary, so we always have a ObjectEntity and Value to attach the File to, so we don't create duplicated Files when syncing every 10 minutes.
        $bijlageObject = $this->entityManager->getRepository('App:ObjectEntity')->findByAnyId($documentMeta['uuid']);

        $mimeType = $documentMeta['mimetype'];

        // Check if we only have to allow PDF documents.
        if (isset($this->configuration['allowPDFOnly']) === true && $this->configuration['allowPDFOnly'] === true && ($mimeType !== 'pdf' && $mimeType !== 'application/pdf')) {
            return null;
        }

        $base64 = $this->fileService->getInhoudDocument($result['id'], $documentMeta['uuid'], $mimeType, $config['source']);

        // This finds the existing Value or creates a new one.
        $value = $bijlageObject->getValueObject("url");

        $this->entityManager->persist($value);

        $url = $this->fileService->createOrUpdateFile($value, $documentMeta['filename'], $base64, $mimeType, $config['endpoint']);

        $documentText = null;
        if (isset($config['extractTextFromDocuments']) === true && $config['extractTextFromDocuments'] === true) {
            // Give the code 5 sec max to extract text.
            $starttime = time();
            // Start timing
            do {
                $documentText = $this->fileService->getTextFromDocument($value, $documentMeta['filename'], $base64, $mimeType, $config['endpoint']);
            } while (isset($documentText) === false && (time() - $starttime) < 5);
        }

        if (isset($documentMeta['extension']) === false) {
            $documentMeta['extension'] = match ($mimeType) {
                'pdf', 'application/pdf' => 'pdf',
                default => '',
            };
        }

        return $this->mappingService->mapping($config['mapping'], array_merge($documentMeta, ['url' => $url, 'documentText' => $documentText]));

    }//end retrieveFile()


    /**
     * Generates file view urls for woo bijlagen documents.
     *
     * @param array $result   The result data that contains the information of file fields.
     * @param array $config   Gateway config objects.
     * @param array $fileURLS File urls we also return.
     *
     * @return array $fileURLS The view urls for files.
     */
    private function getBijlagen(array $result, array $config, array &$fileURLS): array
    {
        $fileFields = [
            'informatieverzoek',
            'inventarisatielijst',
            'besluit',
        ];

        // Prevent having documents with the same names.
        $fileNames = [];

        foreach ($fileFields as $field) {
            if (isset($result['values']["attribute.woo_$field"][0]) === true) {
                $documentMeta     = $result['values']["attribute.woo_$field"][0];
                $fileURLS[$field] = $this->retrieveFile($result, $documentMeta, $config);
                $fileNames[]      = $result['values']["attribute.woo_$field"][0]['filename'];
            }
        }

        $bijlagen = [];
        if (isset($result['values']["attribute.woo_publicatie"]) === true) {
            foreach ($result['values']["attribute.woo_publicatie"] as $documentMeta) {
                if (in_array($documentMeta['filename'], $fileNames) === false) {
                    $bijlagen[]  = $this->retrieveFile($result, $documentMeta, $config);
                    $fileNames[] = $documentMeta['filename'];
                }
            }
        }

        return $bijlagen;

    }//end getBijlagen()


    /**
     * Handles custom logic for processing and hydrating file fields from the given result.
     *
     * @param array    $objectArray  Self array of object.
     * @param array    $result       The result data that contains the information of file fields.
     * @param Endpoint $fileEndpoint The endpoint entity for the file.
     * @param Source   $source       The source entity that provides the source of the result data.
     *
     * @return array                     The hydrated object.
     */
    private function handleCustomLogic(array $objectArray, array $result, Endpoint $fileEndpoint, Source $source): array
    {
        $documentMapping     = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.xxllncDocumentToBijlage.mapping.json", "common-gateway/woo-bundle");
        $customFieldsMapping = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.xxllncCustomFields.mapping.json", "common-gateway/woo-bundle");

        // $fileURLS get set from $this->getBijlagen (see arguments).
        $fileURLS  = [];
        $bijlagen  = $this->getBijlagen($result, ['endpoint' => $fileEndpoint, 'source' => $source, 'mapping' => $documentMapping], $fileURLS);
        $portalURL = $this->configuration['portalUrl'].'/'.$objectArray['_self']['id'];

        return $this->mappingService->mapping($customFieldsMapping, array_merge($objectArray, $fileURLS, ["bijlagen" => $bijlagen, "portalUrl" => $portalURL, "id" => $result['id']]));

    }//end handleCustomLogic()


    /**
     * Fetches objects from xxllnc with pagination.
     *
     * @param Source   $source  The source entity that provides the source of the result data.
     * @param int|null $page    The page we are fetching, increments each iteration.
     * @param array    $results The results from xxllnc api we merge each iteration.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, ?int $page=1, array $results=[]): array
    {
        try {
            $response        = $this->callService->call($source, $this->configuration['zaaksysteemSearchEndpoint'], 'GET', ['query' => ['zapi_page' => $page]]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something went wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage());
            $this->logger->error('Something went wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }

        $results = array_merge($results, $decodedResponse['result']);

        // Pagination xxllnc.
        if (isset($decodedResponse['next']) === true) {
            $page++;
            $results = $this->fetchObjects($source, $page, $results);
        }

        return $results;

    }//end fetchObjects()


    /**
     * Valideert de OIN-waarde om ervoor te zorgen dat deze alleen cijfers bevat.
     *
     * @param mixed $oin De OIN-waarde uit de configuratie.
     *
     * @return string De gevalideerde OIN-waarde.
     *
     * @throws \InvalidArgumentException als de OIN ongeldig is.
     */
    private function validateOin($oin): string
    {
        // Controleer of de OIN is ingesteld en een niet-lege string is
        if (!isset($oin) || !is_string($oin) || trim($oin) === '') {
            throw new \InvalidArgumentException('OIN-waarde ontbreekt of is ongeldig.');
        }

        // Controleer of de OIN alleen uit cijfers bestaat (voorloopnullen toegestaan)
        if (!preg_match('/^\d+$/', $oin)) {
            throw new \InvalidArgumentException('OIN-waarde moet alleen uit cijfers bestaan.');
        }

        return $oin;

    }//end validateOin()


    /**
     * Handles the synchronization of xxllnc cases.
     *
     * @param array $data
     * @param array $configuration
     *
     * @throws CacheException|InvalidArgumentException
     *
     * @return array
     */
    public function syncXxllncCasesHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        isset($this->style) === true && $this->style->success('SyncXxllncCasesService triggered');
        $this->logger->info('SyncXxllncCasesService triggered', ['plugin' => 'common-gateway/woo-bundle']);

        if ($this->wooService->validateHandlerConfig(
            $this->configuration,
            [
                'fileEndpointReference',
                'zaaksysteemSearchEndpoint',
            ],
            'sync XxllncCases'
        ) === false
        ) {
            return [];
        }

        $fileEndpoint     = $this->resourceService->getEndpoint($this->configuration['fileEndpointReference'], 'common-gateway/woo-bundle');
        $source           = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema           = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping          = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        $categorieMapping = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.categorie.mapping.json', 'common-gateway/woo-bundle');
        if ($source instanceof Source === false
            || $schema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncXxllncCasesHandler");

            return [];
        }

        isset($this->style) === true && $this->style->info("Fetching cases from {$source->getLocation()}");
        $this->logger->info("Fetching cases from {$source->getLocation()}", ['plugin' => 'common-gateway/woo-bundle']);

        $results = $this->fetchObjects($source);
        $this->entityManager->flush();

        // Valideer de OIN-waarde
        try {
            $oin = $this->validateOin($this->configuration['oin']);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('Validatie van OIN mislukt: '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);
            isset($this->style) === true && $this->style->error('Validatie van OIN mislukt: '.$e->getMessage());
            return [];
        }

        $idsSynced        = [];
        $responseItems    = [];
        $hydrationService = new HydrationService($this->syncService, $this->entityManager);
        foreach ($results as $result) {
            try {
                isset($this->style) === true && $this->style->info("Trying to synchronize publication with sourceId: {$result['id']}");
                $this->logger->info("Trying to synchronize publication with sourceId: {$result['id']}", ['plugin' => 'common-gateway/woo-bundle']);

                $result       = array_merge(
                    $result,
                    [
                        'settings'    => ['allowPDFOnly' => $configuration['allowPDFOnly']],
                        'autoPublish' => $this->configuration['autoPublish'] ?? true,
                        'organisatie' => [
                            'oin'  => $oin,
                            'naam' => $this->configuration['organisatie'],
                        ],
                    ]
                );
                $mappedResult = $this->mappingService->mapping($mapping, $result);
                // Map categories to prevent multiple variants of the same categorie.
                $mappedResult = $this->mappingService->mapping($categorieMapping, $mappedResult);

                $validationErrors = $this->validationService->validateData($mappedResult, $schema, 'POST');
                if ($validationErrors !== null) {
                    $validationErrors = implode(', ', $validationErrors);
                    $this->logger->warning("SyncXxllncCases validation errors: $validationErrors", ['plugin' => 'common-gateway/woo-bundle']);
                    isset($this->style) === true && $this->style->warning("SyncXxllncCases validation errors: $validationErrors");
                    continue;
                }

                $object = $hydrationService->searchAndReplaceSynchronizations(
                    $mappedResult,
                    $source,
                    $schema,
                    true,
                    true
                );

                // Some custom logic.
                $hydrateArray = $this->handleCustomLogic($object->toArray(), $result, $fileEndpoint, $source);

                // Second time to update Bijlagen.
                $object = $hydrationService->searchAndReplaceSynchronizations(
                    $hydrateArray,
                    $source,
                    $schema,
                    true,
                    false
                );

                $object = $this->entityManager->getRepository('App:ObjectEntity')->findByAnyId($result['id']);

                // Get all synced sourceIds.
                if (empty($object->getSynchronizations()) === false && $object->getSynchronizations()[0]->getSourceId() !== null) {
                    $idsSynced[] = $object->getSynchronizations()[0]->getSourceId();
                }

                $this->entityManager->persist($object);
                $this->cacheService->cacheObject($object);
                $responseItems[] = $object;

                isset($this->style) === true && $this->style->info("Succesfully synced publication with sourceId: {$result['id']}");
                $this->logger->info("Succesfully synced publication with sourceId: {$result['id']}", ['plugin' => 'common-gateway/woo-bundle']);
            } catch (Exception $exception) {
                isset($this->style) === true && $this->style->error("Something went wrong synchronizing sourceId: {$result['id']} with error: {$exception->getMessage()}");
                $this->logger->error("Something went wrong synchronizing sourceId: {$result['id']} with error: {$exception->getMessage()}", ['plugin' => 'common-gateway/woo-bundle']);
                continue;
            }//end try
        }//end foreach

        $this->entityManager->flush();

        $deletedObjectsCount = $this->wooService->deleteUnsyncedObjects($idsSynced, $source, $this->configuration['schema']);

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        $countItems = count($responseItems);
        $logMessage = "Synchronized $countItems cases to woo objects for ".$source->getName()." and deleted $deletedObjectsCount objects";
        isset($this->style) === true && $this->style->success($logMessage);
        $this->logger->info($logMessage, ['plugin' => 'common-gateway/woo-bundle']);

        return $this->data;

    }//end syncXxllncCasesHandler()


}//end class
