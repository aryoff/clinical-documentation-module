import { FormEvent, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

type Document = { document_id: string; template: string; template_version: string; status: string; payload: Record<string, unknown>; encountered_at: string; signed_at: string | null };
export default function Show({ document }: { document: Document }) {
    const [reason, setReason] = useState(""); const [payload, setPayload] = useState("{}");
    const amend = (event: FormEvent) => { event.preventDefault(); try { router.post(route("clinicaldocumentation.amend", document.document_id), { reason, payload: JSON.parse(payload), encountered_at: new Date().toISOString() }); } catch { alert("Addendum payload must be valid JSON."); } };
    return <AuthenticatedLayout><Content><Head title="Clinical document" />
        <Link className="text-sm underline" href={route("clinicaldocumentation.index")}>Back to documents</Link>
        <h1 className="mt-4 text-2xl font-semibold">{document.template} v{document.template_version}</h1><p className="text-sm text-muted-foreground">Signed {document.signed_at ? new Date(document.signed_at).toLocaleString() : "draft"}</p>
        <pre className="mt-6 overflow-auto rounded border bg-muted p-4 text-sm">{JSON.stringify(document.payload, null, 2)}</pre>
        <form className="mt-6 max-w-2xl space-y-3" onSubmit={amend}><h2 className="font-medium">Signed addendum</h2><input required className="w-full rounded border p-2" placeholder="Reason for correction or clarification" value={reason} onChange={(e) => setReason(e.target.value)} /><textarea className="h-40 w-full rounded border p-2 font-mono" value={payload} onChange={(e) => setPayload(e.target.value)} /><button className="rounded bg-primary px-4 py-2 text-primary-foreground">Record signed addendum</button></form>
    </Content></AuthenticatedLayout>;
}
