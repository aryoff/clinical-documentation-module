import { Head, Link } from "@inertiajs/react";
import { type ReactNode } from "react";
import { ClipboardCheck, FileLock2, ShieldAlert } from "lucide-react";
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

type Document = {
  id: string;
  template: string;
  template_version: string;
  status: "draft" | "signed";
  encountered_at: string;
  signed_at: string | null;
};

function WorkflowActionBar() {
  const { t } = useModuleT();
  return (
    <aside className={"space-y-6 p-5 text-sm"}>
      <div>
        <p className={"text-muted-foreground text-xs font-semibold tracking-wider uppercase"}>{t("pages.clinical_workflow")}</p>
        <h2 className={"text-foreground mt-2 font-semibold"}>{t("pages.document_safeguards")}</h2>
      </div>
      <div className={"space-y-3"}>
        <p className={"flex gap-2"}>
          <ClipboardCheck className={"text-primary mt-0.5 size-4"} />{t("pages.accepted_handoff_is_required")}</p>
        <p className={"flex gap-2"}>
          <FileLock2 className={"text-primary mt-0.5 size-4"} />{t("pages.signing_makes_the_source")}</p>
        <p className={"flex gap-2"}>
          <ShieldAlert className={"text-primary mt-0.5 size-4"} />{t("pages.emergency_access_is_separately")}</p>
      </div>
    </aside>
  );
}

const DocumentCard = ({ document }: { document: Document }) => {
  const { t } = useModuleT();
  return (
  <Card className={"hover:bg-muted/50 h-full transition-colors"}>
    <CardHeader className={"flex-row items-start justify-between space-y-0"}>
      <div>
        <CardTitle className={"text-base"}>
          {document.template}{" "}
          <span className={"text-muted-foreground"}>
            {t("shared.template_version", { version: document.template_version })}
          </span>
        </CardTitle>
        <CardDescription className={"mt-2"}>
          {t("index.encountered", { at: new Date(document.encountered_at).toLocaleString() })}
        </CardDescription>
      </div>
      <Badge variant={document.status === "signed" ? "default" : "secondary"}>
        {t(`shared.document_status.${document.status}`)}
      </Badge>
    </CardHeader>
    <CardContent className={"text-muted-foreground text-sm"}>
      {document.signed_at
        ? t("index.signed_at", { at: new Date(document.signed_at).toLocaleString() })
        : t("index.private_draft")}
    </CardContent>
  </Card>
);
};

const Index = ({
  documents,
  activeRecords = [],
}: {
  documents: { data: Document[] };
  activeRecords?: { patient_id: string }[];
}) => {
  const { t } = useModuleT();
  // Reading this list and authoring against it are separate authorities. A
  // reader who lost the authoring one has the edit route stripped from Ziggy,
  // and `route()` on a name Ziggy has never heard of throws rather than
  // handing back a dead href — so their own drafts are listed without a way in.
  const canAuthor = route().has("clinicaldocumentation.edit");

  return (
    <>
      <Head title={t("pages.clinical_documents")} />
      <p className={"text-muted-foreground mb-6 text-sm"}>{t("pages.review_your_private_drafts")}</p>
      {route().has("clinicaldocumentation.records.show") &&
        activeRecords.length > 0 && (
          <section className={"mb-6 space-y-3"}>
            <h2 className={"text-lg font-semibold"}>{t("pages.active_clinical_records")}</h2>
            {activeRecords.map((record) => (
              <Link
                className={"text-primary block text-sm underline"}
                key={record.patient_id}
                href={route(
                  "clinicaldocumentation.records.show",
                  record.patient_id,
                )}
              >
                {t("index.review_record", { patient: record.patient_id })}
              </Link>
            ))}
          </section>
        )}
      <div className={"grid gap-4 lg:grid-cols-2"}>
        {documents.data.map((document) =>
          document.status === "draft" && !canAuthor ? (
            <DocumentCard key={document.id} document={document} />
          ) : (
            <Link
              key={document.id}
              href={route(
                document.status === "draft"
                  ? "clinicaldocumentation.edit"
                  : "clinicaldocumentation.show",
                document.id,
              )}
            >
              <DocumentCard document={document} />
            </Link>
          ),
        )}
        {documents.data.length === 0 && (
          <Card className={"lg:col-span-2"}>
            <CardHeader>
              <CardTitle>{t("pages.no_clinical_documents_yet")}</CardTitle>
              <CardDescription>{t("pages.accepted_handoffs_will_open")}</CardDescription>
            </CardHeader>
          </Card>
        )}
      </div>
    </>
  );
};

Index.layout = (page: ReactNode) => (
  <AuthenticatedLayout header={(t) => t("pages.clinical_documents")}>
    <Content actionBar={<WorkflowActionBar />}>{page}</Content>
  </AuthenticatedLayout>
);

export default Index;
