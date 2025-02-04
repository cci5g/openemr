<?php

/**
 * FhirGoalService.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRGoal;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRDate;
use OpenEMR\FHIR\R4\FHIRElement\FHIRGoalLifecycleStatus;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRResource\FHIRGoal\FHIRGoalTarget;
use OpenEMR\Services\CarePlanService;
use OpenEMR\Services\CodeTypesService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;

class FhirGoalService extends FhirServiceBase implements IResourceUSCIGProfileService
{
    /**
     * @var CarePlanService
     */
    private $service;

    const USCGI_PROFILE_URI = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-goal';

    public function __construct()
    {
        parent::__construct();
        // goals are stored inside the care plan forms
        $this->service = new CarePlanService(CarePlanService::TYPE_GOAL);
    }

    /**
     * Returns an array mapping FHIR Resource search parameters to OpenEMR search parameters
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            // note even though we label this as a uuid, it is a SURROGATE UID because of the nature of how goals are stored
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, ['uuid']),
        ];
    }

    /**
     * Parses an OpenEMR careTeam record, returning the equivalent FHIR CareTeam Resource
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRCareTeam
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $goal = new FHIRGoal();

        $fhirMeta = new FHIRMeta();
        $fhirMeta->setVersionId('1');
        $fhirMeta->setLastUpdated(gmdate('c'));
        $goal->setMeta($fhirMeta);

        $fhirId = new FHIRId();
        $fhirId->setValue($dataRecord['uuid']);
        $goal->setId($fhirId);

        if (isset($dataRecord['puuid'])) {
            $goal->setSubject(UtilsService::createRelativeReference("Patient", $dataRecord['puuid']));
        } else {
            $goal->setSubject(UtilsService::createDataMissingExtension());
        }

        $lifecycleStatus = new FHIRGoalLifecycleStatus();
        $lifecycleStatus->setValue("active");
        $goal->setLifecycleStatus($lifecycleStatus);


        // ONC only requires a descriptive text.  Future FHIR implementors can grab these details and populate the
        // activity element if they so choose, for now we just return the combined description of the care plan.
        if (!empty($dataRecord['details'])) {
            $text = $this->getCarePlanTextFromDetails($dataRecord['details']);
            $codeableConcept = new FHIRCodeableConcept();
            $codeableConcept->setText($text['text']);
            $goal->setDescription($codeableConcept);

            $codeTypeService = new CodeTypesService();
            foreach ($dataRecord['details'] as $detail) {
                $fhirGoalTarget = new FHIRGoalTarget();
                if (!empty($detail['date'])) {
                    $fhirDate = new FHIRDate();
                    $fhirDate->setValue($detail['date']);
                    $fhirGoalTarget->setDueDate($fhirDate);
                } else {
                    $fhirGoalTarget->setDueDate(UtilsService::createDataMissingExtension());
                }

                if (!empty($detail['description'])) {
                    // if description is populated we also have to populate the measure with the correct code
                    $fhirGoalTarget->setDetailString($detail['description']);

                    if (!empty($detail['code'])) {
                        $codeText = $codeTypeService->lookup_code_description($detail['code']);
                        $codeSystem = $codeTypeService->getSystemForCode($detail['code']);

                        $targetCodeableConcept = new FHIRCodeableConcept();
                        $coding = new FhirCoding();
                        $coding->setCode($detail['code']);
                        if (empty($codeText)) {
                            $coding->setDisplay(UtilsService::createDataMissingExtension());
                        } else {
                            $coding->setDisplay(xlt($codeText));
                        }

                        $coding->setSystem($codeSystem); // these should always be LOINC but we want this generic
                        $targetCodeableConcept->addCoding($coding);
                        $fhirGoalTarget->setMeasure($targetCodeableConcept);
                    } else {
                        $fhirGoalTarget->setMeasure(UtilsService::createDataMissingExtension());
                    }
                }
                $goal->addTarget($fhirGoalTarget);
            }
        }

        if ($encode) {
            return json_encode($goal);
        } else {
            return $goal;
        }
    }

    /**
     * Performs a FHIR Resource lookup by FHIR Resource ID
     *
     * @param $fhirResourceId //The OpenEMR record's FHIR Resource ID.
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     */
    public function getOne($fhirResourceId, $puuidBind = null)
    {
        $search = [
            '_id' => $fhirResourceId
        ];
        if (!empty($puuidBind)) {
            $search['patient'] = 'Patient/' . $puuidBind;
        }
        return $this->getAll($search);
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     *
     * @param  array openEMRSearchParameters OpenEMR search fields
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult
     */
    public function searchForOpenEMRRecords($openEMRSearchParameters, $puuidBind = null)
    {
        return $this->service->search($openEMRSearchParameters, true, $puuidBind);
    }

    public function parseFhirResource($fhirResource = array())
    {
        // TODO: If Required in Future
    }

    public function insertOpenEMRRecord($openEmrRecord)
    {
        // TODO: If Required in Future
    }

    public function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord)
    {
        // TODO: If Required in Future
    }
    public function createProvenanceResource($dataRecord, $encode = false)
    {
        $provenanceService = new FhirProvenanceService();
        $provenance = $provenanceService->createProvenanceForDomainResource($dataRecord);
        return $provenance;
    }

    public function getProfileURIs(): array
    {
        return [self::USCGI_PROFILE_URI];
    }

    public function getPatientContextSearchField(): FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('patient', SearchFieldType::REFERENCE, [new ServiceField('puuid', ServiceField::TYPE_UUID)]);
    }

    private function getCarePlanTextFromDetails($details)
    {
        $descriptions = [];
        foreach ($details as $detail) {
            // use description or fallback on codetext if needed
            $descriptions[] = $detail['description'] ?? $detail['codetext'] ?? "";
        }
        $carePlanText = ['text' => implode("\n", $descriptions), "xhtml" => ""];
        if (!empty($descriptions)) {
            $carePlanText['xhtml'] = "<p>" . implode("</p><p>", $descriptions) . "</p>";
        }
        return $carePlanText;
    }
}
