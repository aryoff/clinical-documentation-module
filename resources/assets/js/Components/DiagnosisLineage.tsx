import { Badge } from "@/Components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { useModuleT } from "@/hooks/useModuleT";

export const selectClasses = "h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs";

export type AssertionFact = {
    assertion_id: string;
    lineage_id: string;
    revision: number;
    document_id: string;
    registration_id: string | null;
    patient_id: string;
    coding_system: string;
    code: string;
    display: string;
    assertion_type: string;
    note: string | null;
    asserted_by: string;
    asserted_by_name: string;
    asserted_at: string;
};

export type EvidenceFact = {
    evidence_id: string;
    source_owner: string;
    result_reference_id: string;
    coding_system: string;
    code: string;
    display: string;
    summary: string | null;
    observed_at: string;
    released_by: string;
};

export type LineageAssertion = AssertionFact & {
    is_current: boolean;
    supersedes_assertion_id: string | null;
    superseded_by: string | null;
    evidence: EvidenceFact[];
};

export type DiagnosisLineage = {
    patient_id: string;
    purpose: string;
    current: AssertionFact[];
    lineages: { lineage_id: string; assertions: LineageAssertion[] }[];
};

/**
 * The Clinical Diagnosis Read, rendered as what it is: every lineage in
 * revision order, with the superseded revisions still legible. A corrected
 * diagnosis is history rather than a mistake to hide, so nothing here is
 * collapsed away — only the head is marked as the one that still counts.
 */
export const DiagnosisLineageView = ({
  lineage,
}: {
  lineage: DiagnosisLineage;
}) => {
  const { t } = useModuleT();
    if (lineage.lineages.length === 0) {
        return <p className={"text-sm text-muted-foreground"} id={"diagnosis_lineage_empty"}>{t("diagnosis_lineage.no_diagnosis_has_been")}</p>;
    }

    return <div className={"space-y-4"} id={"diagnosis_lineage"}>
        {lineage.lineages.map((thread) => <Card key={thread.lineage_id} className={"border-l-4 border-l-primary/40"}>
            <CardHeader className={"pb-3"}>
                <CardTitle className={"text-base"}>{thread.assertions[thread.assertions.length - 1]?.display}</CardTitle>
                <CardDescription>{t("diagnosis_lineage.revisions", { count: thread.assertions.length })}</CardDescription>
            </CardHeader>
            <CardContent className={"space-y-3"}>
                {thread.assertions.map((assertion) => <div key={assertion.assertion_id} className={"rounded-md border p-3"} data-assertion-id={assertion.assertion_id}>
                    <div className={"flex flex-wrap items-center gap-2"}>
                        <span className={"font-mono text-sm font-semibold"}>{assertion.code}</span>
                        <span className={"text-sm"}>{assertion.display}</span>
                        <Badge variant={"outline"}>{t("diagnosis_lineage.revision_badge", { revision: assertion.revision })}</Badge>
                        <Badge variant={"outline"}>{t(`diagnosis_lineage.type.${assertion.assertion_type}`)}</Badge>
                        {assertion.is_current
                            ? <Badge>{t("diagnosis_lineage.current_diagnosis")}</Badge>
                            : <Badge variant={"secondary"}>{t("diagnosis_lineage.superseded")}</Badge>}
                    </div>
                    <p className={"mt-2 text-xs text-muted-foreground"}>
                        {t("diagnosis_lineage.asserted_by_at", { by: assertion.asserted_by_name, at: new Date(assertion.asserted_at).toLocaleString() })}
                    </p>
                    {assertion.note && <p className={"mt-2 text-sm"}>{assertion.note}</p>}
                    {assertion.evidence.length > 0 && <ul className={"mt-2 space-y-1"}>
                        {assertion.evidence.map((evidence) => <li key={evidence.evidence_id} className={"text-xs text-muted-foreground"}>
                            {t("diagnosis_lineage.cited", { owner: evidence.source_owner, code: evidence.code, display: evidence.display })}
                        </li>)}
                    </ul>}
                </div>)}
            </CardContent>
        </Card>)}
    </div>;
};
