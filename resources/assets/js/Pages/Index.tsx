import { Head, Link } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

type Document = { id: string; template: string; template_version: string; status: "draft" | "signed"; encountered_at: string; signed_at: string | null };

export default function Index({ documents }: { documents: { data: Document[] } }) {
    return <AuthenticatedLayout><Content>
        <Head title="Clinical documents" />
        <h1 className="text-2xl font-semibold">Clinical documents</h1>
        <p className="mt-2 text-sm text-muted-foreground">Authoring begins only from an accepted Clinical Handoff in the originating care workflow.</p>
        <div className="mt-6 divide-y rounded-md border">
            {documents.data.map((document) => <Link key={document.id} className="block p-4 hover:bg-muted" href={route(document.status === "draft" ? "clinicaldocumentation.edit" : "clinicaldocumentation.show", document.id)}>
                <div className="font-medium">{document.template} v{document.template_version}</div>
                <div className="text-sm text-muted-foreground">{document.status} · encountered {new Date(document.encountered_at).toLocaleString()}</div>
            </Link>)}
            {documents.data.length === 0 && <p className="p-4 text-sm text-muted-foreground">No documents authored by you.</p>}
        </div>
    </Content></AuthenticatedLayout>;
}
