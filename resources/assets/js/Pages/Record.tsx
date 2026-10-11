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
const outcomeLabels: Record<string, string> = {
  given: "Given",
  partial: "Partially given",
  held: "Held",
  refused: "Refused",
  missed: "Missed",
};
const basisLabels: Record<string, string> = {
  prescription: "Prescription",
  emergency: "Emergency",
  protocol: "Protocol",
  none: "No basis recorded",
};

const Administration = ({
  fact,
  factId,
  originalFactId,
}: {
  fact: AdministrationFact;
  factId: string;
  originalFactId?: string;
}) => {
  const snapshot = fact.administration;
  const outcome = fact.outcome ?? snapshot?.outcome ?? "unknown";
  const quantity = fact.dose?.quantity ?? snapshot?.dose_quantity;
  const unit = fact.dose?.unit ?? snapshot?.dose_unit;
  return (
    <Card id={`fact-${factId}`}>
      <CardHeader>
        <Badge className="w-fit" variant="secondary">
          {fact.type === "corrected"
            ? "Administration correction"
            : fact.type === "basis_reconciled"
              ? "Basis reconciled"
              : "Medication administration"}
        </Badge>
        <CardTitle>
          {fact.medicine?.product_name ??
            snapshot?.product_name ??
            "Medicine not supplied"}{" "}
          · {outcomeLabels[outcome] ?? `Unknown outcome (${outcome})`}
        </CardTitle>
        <CardDescription>Registration {fact.registration_id}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3 text-sm">
        <p>
          {quantity == null ? "No dose recorded" : `${quantity} ${unit ?? ""}`}{" "}
          · {fact.route ?? snapshot?.route ?? "Route not supplied"}
        </p>
        <p>
          Administered by{" "}
          {fact.administered_by?.name ??
            snapshot?.administered_by_name ??
            "Not supplied"}{" "}
          at{" "}
          {fact.administered_at ?? snapshot?.administered_at ?? "Not supplied"}
        </p>
        {fact.care_location && <p>Care location: {fact.care_location.name}</p>}
        <p>
          Basis:{" "}
          {basisLabels[fact.basis.type] ?? `Unknown basis (${fact.basis.type})`}{" "}
          · {fact.basis.state ?? "State not supplied"}
        </p>
        {fact.reason && <p>Outcome reason: {fact.reason}</p>}
        {fact.corrects_administration_id && (
          <p>
            Corrects source administration:{" "}
            <span className="font-mono">{fact.corrects_administration_id}</span>
            . {fact.correction_reason}
          </p>
        )}
        {originalFactId &&
          (fact.corrects_administration_id ||
            fact.type === "basis_reconciled") && (
            <a
              className="text-primary block underline"
              href={`#fact-${originalFactId}`}
            >
              View original administration
            </a>
          )}
        {fact.reconciled_by && (
          <p>
            Reconciled by {fact.reconciled_by.name}; original administration
            stays unchanged.
          </p>
        )}
        <p className="text-muted-foreground break-all">
          Source: {fact.source_reference}
        </p>
        <details>
          <summary className="cursor-pointer font-medium">
            Verification and source snapshot
          </summary>
          <pre className="bg-muted mt-3 overflow-auto rounded p-3 break-all whitespace-pre-wrap">
            {JSON.stringify(fact, null, 2)}
          </pre>
        </details>
      </CardContent>
    </Card>
  );
};

const Record = ({ record }: RecordProps) => {
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
      <Head title="Active clinical record" />
      <Content description="Signed documents and source-owned administration evidence, in chronological order. Corrections preserve the original evidence.">
        <p className="text-muted-foreground mb-4 text-sm">
          Patient {record.patient_id}
        </p>
        {route().has("clinicaldocumentation.index") && (
          <Link
            className="text-primary mb-5 block text-sm underline"
            href={route("clinicaldocumentation.index")}
          >
            Back to documents
          </Link>
        )}
        <ol className="space-y-5">
          {record.entries.map((entry) => (
            <li key={entry.id}>
              <p className="text-muted-foreground mb-2 text-sm">
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
                        : "Signed addendum"}
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-3 text-sm">
                    {entry.kind === "clinical_addendum" && (
                      <p>{entry.addendum.reason}</p>
                    )}
                    <pre className="overflow-auto break-all whitespace-pre-wrap">
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
                        className="text-primary underline"
                        href={route(
                          "clinicaldocumentation.show",
                          entry.kind === "clinical_document"
                            ? entry.document.document_id
                            : entry.addendum.document_id,
                        )}
                      >
                        Read signed document
                      </Link>
                    )}
                  </CardContent>
                </Card>
              )}
            </li>
          ))}
        </ol>
        {record.entries.length === 0 && (
          <p>No finalized clinical evidence recorded.</p>
        )}
      </Content>
    </>
  );
};
Record.layout = (page: ReactNode) => (
  <AuthenticatedLayout header="Active clinical record">
    {page}
  </AuthenticatedLayout>
);
export default Record;
