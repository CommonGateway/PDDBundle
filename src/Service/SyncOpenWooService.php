<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\File;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\ObjectEntityService;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\ValidationService;
use CommonGateway\CoreBundle\Service\CacheService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;
use Smalot\PdfParser\Parser;

/**
 * Service responsible for synchronizing OpenWoo objects to woo objects.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class SyncOpenWooService
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
     * @var FileService
     */
    private FileService $fileService;

    /**
     * @var ObjectEntityService
     */
    private ObjectEntityService $gatewayOEService;

    /**
     * @var WooService
     */
    private WooService $wooService;

    /**
     * @var Parser $pdfParser.
     */
    private Parser $pdfParser;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * SyncOpenWooService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService            $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService         $mappingService
     * @param LoggerInterface        $pluginLogger
     * @param ValidationService      $validationService
     * @param CacheService           $cacheService
     * @param FileService            $fileService
     * @param ObjectEntityService    $gatewayOEService
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
        CacheService $cacheService,
        FileService $fileService,
        ObjectEntityService $gatewayOEService,
        WooService $wooService
    ) {
        $this->resourceService   = $resourceService;
        $this->callService       = $callService;
        $this->syncService       = $syncService;
        $this->entityManager     = $entityManager;
        $this->mappingService    = $mappingService;
        $this->logger            = $pluginLogger;
        $this->validationService = $validationService;
        $this->cacheService      = $cacheService;
        $this->fileService       = $fileService;
        $this->gatewayOEService  = $gatewayOEService;
        $this->wooService        = $wooService;
        $this->pdfParser         = new Parser();

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
     * Fetches objects from openWoo with pagination.
     *
     * @param Source   $source    The source entity that provides the source of the result data.
     * @param string   $categorie The type of object we are fetching.
     * @param int|null $page      The page we are fetching, increments each iteration.
     * @param array    $results   The results from xxllnc api we merge each iteration.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, string $categorie, ?int $page=1, array $results=[])
    {
        try {
            $response        = $this->callService->call($source, $this->configuration['sourceEndpoint'], 'GET', ['query' => ['page' => $page]]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something went wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage());
            $this->logger->error('Something went wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }

        switch ($categorie) {
        case 'Woo verzoek':
            $results = array_merge($results, $decodedResponse['WOOverzoeken']);
            break;
        case 'Convenant':
            $results = array_merge($results, $decodedResponse['Convenantenverzoeken']);
            break;
        }

        // Pagination xxllnc.
        if (isset($decodedResponse['pagination']) === true && $decodedResponse['pagination']['pages']['current'] < $decodedResponse['pagination']['pages']['total']) {
            $page++;
            $results = $this->fetchObjects($source, $categorie, $page, $results);
        }

        return $results;

    }//end fetchObjects()


    /**
     * Handles the synchronization of openwoo objects.
     *
     * @param array $data
     * @param array $configuration
     *
     * @throws CacheException|InvalidArgumentException
     *
     * @return array
     */
    public function syncOpenWooHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        isset($this->style) === true && $this->style->success('syncOpenWooHandler triggered');
        $this->logger->info('syncOpenWooHandler triggered', ['plugin' => 'common-gateway/woo-bundle']);

        if ($this->wooService->validateHandlerConfig($this->configuration, ['sourceEndpoint']) === false) {
            return [];
        }

        $source           = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema           = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping          = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        $categorieMapping = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.categorie.mapping.json', 'common-gateway/woo-bundle');
        if ($source instanceof Source === false
            || $schema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncOpenWooHandler");
            $this->logger->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncOpenWooHandler", ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }//end if

        $categorie = '';
        switch ($mapping->getReference()) {
        case 'https://commongateway.nl/mapping/woo.openWooToWoo.mapping.json':
            $categorie = 'Woo verzoek';
            break;
        case 'https://commongateway.nl/mapping/woo.openConvenantToWoo.mapping.json':
            $categorie = 'Convenant';
            break;
        }

        isset($this->style) === true && $this->style->info("Fetching objects from {$source->getLocation()}");
        $this->logger->info("Fetching objects from {$source->getLocation()}", ['plugin' => 'common-gateway/woo-bundle']);

        $results = $this->fetchObjects($source, $categorie);
        if (empty($results) === true) {
            $this->logger->info('No results found, ending SyncOpenWooService', ['plugin' => 'common-gateway/woo-bundle']);
            isset($this->style) === true && $this->style->info('No results found, ending SyncOpenWooService');
            return $this->data;
        }

        $this->entityManager->flush();

        $customFields = [
            'organisatie' => [
                'oin'  => $this->configuration['oin'],
                'naam' => $this->configuration['organisatie'],
            ],
            'categorie'   => $categorie,
            'autoPublish' => $this->configuration['autoPublish'] ?? true,
        ];

        $idsSynced        = [];
        $responseItems    = [];
        $documents        = [];
        $hydrationService = new HydrationService($this->syncService, $this->entityManager);
        foreach ($results as $result) {
            try {
                $result       = array_merge($result, $customFields);
                $mappedResult = $this->mappingService->mapping($mapping, $result);
                // Map categories to prevent multiple variants of the same categorie.
                $mappedResult = $this->mappingService->mapping($categorieMapping, $mappedResult);
                if (isset($mappedResult['samenvatting']) === true) {
                    $mappedResult['samenvatting'] = html_entity_decode($mappedResult['samenvatting']);
                }

                $validationErrors = $this->validationService->validateData($mappedResult, $schema, 'POST');
                if ($validationErrors !== null) {
                    $validationErrors = implode(', ', $validationErrors);
                    $this->logger->warning("SyncOpenWoo validation errors: $validationErrors", ['plugin' => 'common-gateway/woo-bundle']);
                    isset($this->style) === true && $this->style->warning("SyncOpenWoo validation errors: $validationErrors");
                    continue;
                }

                $object = $hydrationService->searchAndReplaceSynchronizations(
                    $mappedResult,
                    $source,
                    $schema,
                    true,
                    true
                );

                // Get all synced sourceIds.
                if (empty($object->getSynchronizations()) === false && $object->getSynchronizations()[0]->getSourceId() !== null) {
                    $idsSynced[] = $object->getSynchronizations()[0]->getSourceId();
                }

                $this->entityManager->persist($object);
                $this->cacheService->cacheObject($object);
                $responseItems[] = $object;

                $renderedObject = $object->toArray();
                $documents      = array_merge($documents, $renderedObject['bijlagen']);
                if (isset($renderedObject['metadata']['verzoek']['informatieverzoek']) === true) {
                    $documents[] = $renderedObject['metadata']['verzoek']['informatieverzoek'];
                }

                if (isset($renderedObject['verzoek']['besluit']) === true) {
                    $documents[] = $renderedObject['metadata']['verzoek']['besluit'];
                }
            } catch (Exception $exception) {
                $this->logger->error("Something went wrong synchronizing sourceId: {$result['UUID']} with error: {$exception->getMessage()}", ['plugin' => 'common-gateway/woo-bundle']);
                isset($this->style) === true && $this->style->warning("Something went wrong synchronizing sourceId: {$result['UUID']} with error: {$exception->getMessage()}");

                continue;
            }//end try
        }//end foreach

        $this->entityManager->flush();

        foreach ($documents as $document) {
            $documentData['document'] = $document;
            $documentData['source']   = $source->getReference();
            $this->gatewayOEService->dispatchEvent('commongateway.action.event', $documentData, 'woo.openwoo.document.created');
        }

        $deletedObjectsCount = $this->wooService->deleteUnsyncedObjects($idsSynced, $source, $this->configuration['schema'], $categorie);

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        $countItems = count($responseItems);
        $logMessage = "Synchronized $countItems cases to woo objects for ".$source->getName()." and deleted $deletedObjectsCount objects";
        isset($this->style) === true && $this->style->success($logMessage);
        $this->logger->info($logMessage, ['plugin' => 'common-gateway/woo-bundle']);

        return $this->data;

    }//end syncOpenWooHandler()


    public function syncOpenWooDocumentHandler(array $data, array $config): array
    {
        $source   = $this->resourceService->getSource($data['source'], 'common-gateway/woo-bundle');
        $document = $data['document'];
        $endpoint = $this->resourceService->getEndpoint($config['endpoint'], 'common-gateway/woo-bundle');

        if (substr($document['url'], 0, strlen($source->getLocation())) === $source->getLocation()) {
            $path = substr($document['url'], strlen($source->getLocation()));
        } else {
            $this->logger->error('Url of document does not correspond with source');

            return $data;
        }

        $bijlageObject = $this->entityManager->getRepository('App:ObjectEntity')->find($document['_self']['id']);
        if ($bijlageObject instanceof ObjectEntity === false) {
            return $data;
        }

        $value = $bijlageObject->getValueObject('url');

        if ($value->getFiles()->count() > 0) {
            $file = $value->getFiles()->first();
        } else {
            $file = new File();
        }

        $response = $this->callService->call($source, $path);

        $file->setBase64(base64_encode($response->getBody()));
        $file->setMimeType($response->getHeader('content-type')[0]);
        if (empty($response->getHeader('content-length')) === false) {
            $file->setSize($response->getHeader('content-length')[0]);
        } else {
            $file->setSize($this->gatewayOEService->getBase64Size($file->getBase64()));
        }

        $file->setName(($document['titel'] ?? $document['url']));

        $file->setValue($value);

        $this->entityManager->persist($file);

        $extension = null;
        switch ($file->getMimeType()) {
        case 'pdf':
        case 'application/pdf':
            $extension = 'pdf';
            try {
                $pdf  = $this->pdfParser->parseContent(\Safe\base64_decode($file->getBase64()));
                $text = $pdf->getText();
            } catch (\Exception $e) {
                $this->logger->error('Something went wrong extracting text from '.$document['url'].' '.$e->getMessage());
                $this->style && $this->style->error('Something went wrong extracting text from '.$document['url'].' '.$e->getMessage());

                $text = null;
            }
            break;
        default:
            $text = null;
        }

        if (isset($extension) === false) {
            $explodedFilename = explode('.', ($document['titel'] ?? $document['url']));
            $extension        = end($explodedFilename);
        }

        $file->setExtension($extension);

        $body = [
            'extension'    => $extension,
            'documentText' => $text,
        ];
        if (isset($data['keepUrl']) === false || $data['keepUrl'] !== true) {
            $body['url'] = $this->fileService->generateDownloadEndpoint($file->getId()->toString(), $endpoint);
        }

        $bijlageObject->hydrate($body);

        $this->entityManager->persist($bijlageObject);

        $this->entityManager->flush();

        $this->cacheService->cacheObject($bijlageObject);

        $data['document'] = $bijlageObject->toArray();

        return $data;

    }//end syncOpenWooDocumentHandler()


}//end class
