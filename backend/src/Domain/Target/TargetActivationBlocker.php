<?php

declare(strict_types=1);

namespace App\Domain\Target;

enum TargetActivationBlocker: string
{
    case MinimumFreeUnconfigured = 'minimum_free_unconfigured';
    case AllowedNodesEmpty = 'allowed_nodes_empty';
    case ConcurrencyUnconfigured = 'concurrency_unconfigured';
    case CandidateEvidenceMissing = 'candidate_evidence_missing';
    case CandidateRejected = 'candidate_rejected';
    case CandidateEvidenceStale = 'candidate_evidence_stale';
    case CandidateEvidenceFuture = 'candidate_evidence_future';
    case InventoryEvidenceMissing = 'inventory_evidence_missing';
    case InventoryEvidenceStale = 'inventory_evidence_stale';
    case InventoryEvidenceFuture = 'inventory_evidence_future';
    case CapacityEvidenceMissing = 'capacity_evidence_missing';
    case CapacityEvidenceStale = 'capacity_evidence_stale';
    case CapacityEvidenceFuture = 'capacity_evidence_future';
    case ExecutorEvidenceMissing = 'executor_evidence_missing';
    case ExecutorEvidenceStale = 'executor_evidence_stale';
    case ExecutorEvidenceFuture = 'executor_evidence_future';
    case ExecutorUnauthorized = 'executor_unauthorized';
    case PbsMappingRequired = 'pbs_mapping_required';
    case PbsMappingUnexpected = 'pbs_mapping_unexpected';
}
