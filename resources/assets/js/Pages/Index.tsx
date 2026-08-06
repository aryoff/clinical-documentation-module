import { Head, Link } from "@inertiajs/react";
import { type ReactNode } from "react";
import { ClipboardCheck, FileLock2, ShieldAlert } from "lucide-react";
import { Badge } from "@/Components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
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
    return <aside className="space-y-6 p-5 text-sm">
        <div>
            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Clinical workflow</p>
            <h2 className="mt-2 font-semibold text-foreground">Document safeguards</h2>
        </div>
        <div className="space-y-3">
            <p className="flex gap-2"><ClipboardCheck className="mt-0.5 size-4 text-primary" />Accepted handoff is required to create a draft.</p>
            <p className="flex gap-2"><FileLock2 className="mt-0.5 size-4 text-primary" />Signing makes the source record immutable.</p>
            <p className="flex gap-2"><ShieldAlert className="mt-0.5 size-4 text-primary" />Emergency access is separately reviewed.</p>
        </div>
    </aside>;
}

const Index = ({ documents }: { documents: { data: Document[] } }) => <>
    <Head title="Clinical documents" />
    <p className="mb-6 text-sm text-muted-foreground">Review your private drafts and signed clinical evidence. Start a document from an accepted Clinical Handoff in its originating care workflow.</p>
    <div className="grid gap-4 lg:grid-cols-2">
        {documents.data.map((document) => <Link key={document.id} href={route(document.status === "draft" ? "clinicaldocumentation.edit" : "clinicaldocumentation.show", document.id)}>
            <Card className="h-full transition-colors hover:bg-muted/50">
                <CardHeader className="flex-row items-start justify-between space-y-0">
                    <div>
                        <CardTitle className="text-base">{document.template} <span className="text-muted-foreground">v{document.template_version}</span></CardTitle>
                        <CardDescription className="mt-2">Encountered {new Date(document.encountered_at).toLocaleString()}</CardDescription>
                    </div>
                    <Badge variant={document.status === "signed" ? "default" : "secondary"}>{document.status}</Badge>
                </CardHeader>
                <CardContent className="text-sm text-muted-foreground">{document.signed_at ? `Signed ${new Date(document.signed_at).toLocaleString()}` : "Private draft — continue authoring"}</CardContent>
            </Card>
        </Link>)}
        {documents.data.length === 0 && <Card className="lg:col-span-2">
            <CardHeader>
                <CardTitle>No clinical documents yet</CardTitle>
                <CardDescription>Accepted handoffs will open the appropriate authoring path.</CardDescription>
            </CardHeader>
        </Card>}
    </div>
</>;

Index.layout = (page: ReactNode) => <AuthenticatedLayout header="Clinical documents">
    <Content actionBar={<WorkflowActionBar />}>{page}</Content>
</AuthenticatedLayout>;

export default Index;
