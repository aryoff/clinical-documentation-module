import { Head, Link } from "@inertiajs/react";
import { type ReactNode } from "react";
import { Badge } from "@/Components/ui/badge";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/Components/ui/card";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { useModuleT } from "@/hooks/useModuleT";
import { joinParts } from "../Support/ids";

type AdministrationFact = {
  type: string;
  source_reference: string;
  administration_id: string;
  registration_id: string;
  corrects_administration_id?: string | null;
  correction_reason?: string | null;
  outcome?: string;
  reason?: string | null;
  medicine?: { product_name: string };
  dose?: { quantity: string | null; unit: string | null } | null;
  route?: string | null;
  administered_at?: string;
  administered_by?: { name: string };
  care_location?: { name: string };
  basis: { type: string; state?: string };
  reconciled_by?: { name: string };
  administration?: {
    product_name?: string;
    outcome?: string;
    dose_quantity?: string | null;
    dose_unit?: string | null;
    route?: string;
    administered_at?: string;
    administered_by_name?: string;
  };
};
type Entry = { id: string; occurred_at: string } & (
  | { kind: "medication_administration"; fact: AdministrationFact }
  | {
      kind: "clinical_document";
      document: {
        document_id: string;
        registration_id: string;
        template: string;
        payload: Record<string, unknown>;
      };
    }
  | {
      kind: "clinical_addendum";
      addendum: {
        document_id: string;
        reason: string;
        payload: Record<string, unknown>;
      };
    }
);

type RecordProps = PageProps & {
  record: { patient_id: string; entries: Entry[] };
};
const outcomeCodes = ["given", "partial", "held", "refused", "missed"];
const basisCodes = ["prescription", "emergency", "protocol", "none"];

const Administration = ({
  fact,
  factId,
  originalFactId,
}: {
  fact: AdministrationFact;
  factId: string;
  originalFactId?: string;
}) => {
  const { t } = useModuleT();
  const snapshot = fact.administration;
  const outcome = fact.outcome ?? snapshot?.outcome ?? "unknown";
  const quantity = fact.dose?.quantity ?? snapshot?.dose_quantity;
  const unit = fact.dose?.unit ?? snapshot?.dose_unit;
  return (
    <Card id={joinParts("", "fact-", factId)}>
      <CardHeader>
        <Badge className={"w-fit"} variant={"secondary"}>
          {fact.type === "corrected"
            ? t("record.administration_correction")
            : fact.type === "basis_reconciled"
              ? t("record.basis_reconciled")
              : t("record.medication_administration")}
        </Badge>
        <CardTitle>
          {t("record.medicine_outcome", {
            medicine: fact.medicine?.product_name ?? snapshot?.product_name ?? t("record.medicine_not_supplied"),
            outcome: outcomeCodes.includes(outcome) ? t(`record.outcome.${outcome}`) : t("record.unknown_outcome", { outcome }),
          })}
        </CardTitle>
        <CardDescription>{t("record.registration", { id: fact.registration_id })}</CardDescription>
      </CardHeader>
      <CardContent className={"space-y-3 text-sm"}>
        <p>
          {t("record.dose_route", {
            dose: quantity == null ? t("record.no_dose_recorded") : `${quantity} ${unit ?? ""}`,
            route: fact.route ?? snapshot?.route ?? t("record.route_not_supplied"),
          })}
        </p>
        <p>
          {t("record.administered_by_at", {
            name: fact.administered_by?.name ?? snapshot?.administered_by_name ?? t("record.not_supplied"),
            at: fact.administered_at ?? snapshot?.administered_at ?? t("record.not_supplied"),
          })}
        </p>
        {fact.care_location && <p>{t("record.care_location", { name: fact.care_location.name })}</p>}
        <p>
          {t("record.basis_line", {
            basis: basisCodes.includes(fact.basis.type) ? t(`record.basis.${fact.basis.type}`) : t("record.unknown_basis", { basis: fact.basis.type }),
            state: fact.basis.state ?? t("record.state_not_supplied"),
          })}
        </p>
        {fact.reason && <p>{t("record.outcome_reason", { reason: fact.reason })}</p>}
        {fact.corrects_administration_id && (
          <p>
            {t("record.corrects_source")}{" "}
            <span className={"font-mono"}>{fact.corrects_administration_id}</span>
            {t("record.correction_reason_suffix", { reason: fact.correction_reason ?? "" })}
          </p>
        )}
        {originalFactId &&
          (fact.corrects_administration_id ||
            fact.type === "basis_reconciled") && (
            <a
              className={"text-primary block underline"}
              href={joinParts("", "#fact-", originalFactId)}
            >{t("record.view_original_administration")}</a>
          )}
        {fact.reconciled_by && (
          <p>
            {t("record.reconciled_by", { name: fact.reconciled_by.name })}
          </p>
        )}
        <p className={"text-muted-foreground break-all"}>
          {t("record.source", { reference: fact.source_reference })}
        </p>
        <details>
          <summary className={"cursor-pointer font-medium"}>{t("record.verification_and_source_snapshot")}</summary>
          <pre className={"bg-muted mt-3 overflow-auto rounded p-3 break-all whitespace-pre-wrap"}>
            {JSON.stringify(fact, null, 2)}
          </pre>
        </details>
      </CardContent>
    </Card>
  );
};

const Record = ({ record }: RecordProps) => {
  const { t } = useModuleT();
  const sourceEntries = new Map<string, string>();
  for (const entry of record.entries) {
    if (
      entry.kind === "medication_administration" &&
      entry.fact.type !== "basis_reconciled"
    ) {
      sourceEntries.set(entry.fact.administration_id, entry.id);
    }
  }
  return (
    <>
      <Head title={t("record.active_clinical_record")} />
      <Content description={t("record.signed_documents_and_source")}>
        <p className={"text-muted-foreground mb-4 text-sm"}>
          {t("record.patient", { id: record.patient_id })}
        </p>
        {route().has("clinicaldocumentation.index") && (
          <Link
            className={"text-primary mb-5 block text-sm underline"}
            href={route("clinicaldocumentation.index")}
          >{t("record.back_to_documents")}</Link>
        )}
        <ol className={"space-y-5"}>
          {record.entries.map((entry) => (
            <li key={entry.id}>
              <p className={"text-muted-foreground mb-2 text-sm"}>
                {new Date(entry.occurred_at).toLocaleString()}
              </p>
              {entry.kind === "medication_administration" ? (
                <Administration
                  fact={entry.fact}
                  factId={entry.id}
                  originalFactId={sourceEntries.get(
                    entry.fact.corrects_administration_id ??
                      entry.fact.administration_id,
                  )}
                />
              ) : (
                <Card>
                  <CardHeader>
                    <CardTitle>
                      {entry.kind === "clinical_document"
                        ? entry.document.template
                        : t("record.signed_addendum")}
                    </CardTitle>
                  </CardHeader>
                  <CardContent className={"space-y-3 text-sm"}>
                    {entry.kind === "clinical_addendum" && (
                      <p>{entry.addendum.reason}</p>
                    )}
                    <pre className={"overflow-auto break-all whitespace-pre-wrap"}>
                      {JSON.stringify(
                        entry.kind === "clinical_document"
                          ? entry.document.payload
                          : entry.addendum.payload,
                        null,
                        2,
                      )}
                    </pre>
                    {route().has("clinicaldocumentation.show") && (
                      <Link
                        className={"text-primary underline"}
                        href={route(
                          "clinicaldocumentation.show",
                          entry.kind === "clinical_document"
                            ? entry.document.document_id
                            : entry.addendum.document_id,
                        )}
                      >{t("record.read_signed_document")}</Link>
                    )}
                  </CardContent>
                </Card>
              )}
            </li>
          ))}
        </ol>
        {record.entries.length === 0 && (
          <p>{t("record.no_finalized_clinical_evidence")}</p>
        )}
      </Content>
    </>
  );
};
Record.layout = (page: ReactNode) => (
  <AuthenticatedLayout header={(t) => t("record.active_clinical_record")}>
    {page}
  </AuthenticatedLayout>
);
export default Record;
