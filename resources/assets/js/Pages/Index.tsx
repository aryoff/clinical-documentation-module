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

type Document = {
  id: string;
  template: string;
  template_version: string;
  status: "draft" | "signed";
  encountered_at: string;
  signed_at: string | null;
};

function WorkflowActionBar() {
  return (
    <aside className="space-y-6 p-5 text-sm">
      <div>
        <p className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
          Clinical workflow
        </p>
        <h2 className="text-foreground mt-2 font-semibold">
          Document safeguards
        </h2>
      </div>
      <div className="space-y-3">
        <p className="flex gap-2">
          <ClipboardCheck className="text-primary mt-0.5 size-4" />
          Accepted handoff is required to create a draft.
        </p>
        <p className="flex gap-2">
          <FileLock2 className="text-primary mt-0.5 size-4" />
          Signing makes the source record immutable.
        </p>
        <p className="flex gap-2">
          <ShieldAlert className="text-primary mt-0.5 size-4" />
          Emergency access is separately reviewed.
        </p>
      </div>
    </aside>
  );
}

const DocumentCard = ({ document }: { document: Document }) => (
  <Card className="hover:bg-muted/50 h-full transition-colors">
    <CardHeader className="flex-row items-start justify-between space-y-0">
      <div>
        <CardTitle className="text-base">
          {document.template}{" "}
          <span className="text-muted-foreground">
            v{document.template_version}
          </span>
        </CardTitle>
        <CardDescription className="mt-2">
          Encountered {new Date(document.encountered_at).toLocaleString()}
        </CardDescription>
      </div>
      <Badge variant={document.status === "signed" ? "default" : "secondary"}>
        {document.status}
      </Badge>
    </CardHeader>
    <CardContent className="text-muted-foreground text-sm">
      {document.signed_at
        ? `Signed ${new Date(document.signed_at).toLocaleString()}`
        : "Private draft — continue authoring"}
    </CardContent>
  </Card>
);

const Index = ({
  documents,
  activeRecords = [],
}: {
  documents: { data: Document[] };
  activeRecords?: { patient_id: string }[];
}) => {
  // Reading this list and authoring against it are separate authorities. A
  // reader who lost the authoring one has the edit route stripped from Ziggy,
  // and `route()` on a name Ziggy has never heard of throws rather than
  // handing back a dead href — so their own drafts are listed without a way in.
  const canAuthor = route().has("clinicaldocumentation.edit");

  return (
    <>
      <Head title="Clinical documents" />
      <p className="text-muted-foreground mb-6 text-sm">
        Review your private drafts and signed clinical evidence. Start a
        document from an accepted Clinical Handoff in its originating care
        workflow.
      </p>
      {route().has("clinicaldocumentation.records.show") &&
        activeRecords.length > 0 && (
          <section className="mb-6 space-y-3">
            <h2 className="text-lg font-semibold">Active clinical records</h2>
            {activeRecords.map((record) => (
              <Link
                className="text-primary block text-sm underline"
                key={record.patient_id}
                href={route(
                  "clinicaldocumentation.records.show",
                  record.patient_id,
                )}
              >
                Review clinical record: {record.patient_id}
              </Link>
            ))}
          </section>
        )}
      <div className="grid gap-4 lg:grid-cols-2">
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
          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle>No clinical documents yet</CardTitle>
              <CardDescription>
                Accepted handoffs will open the appropriate authoring path.
              </CardDescription>
            </CardHeader>
          </Card>
        )}
      </div>
    </>
  );
};

Index.layout = (page: ReactNode) => (
  <AuthenticatedLayout header="Clinical documents">
    <Content actionBar={<WorkflowActionBar />}>{page}</Content>
  </AuthenticatedLayout>
);

export default Index;
