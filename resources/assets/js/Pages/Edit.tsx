import { FormEvent, useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

type Document = { id: string; payload: Record<string, unknown>; encountered_at: string };
type ClinicalDocumentForm = { payload: Record<string, any>; encountered_at: string };
export default function Edit({ document }: { document: Document }) {
    const { put, processing, setData } = useForm<ClinicalDocumentForm>({ payload: document.payload, encountered_at: document.encountered_at });
    const [payload, setPayload] = useState(JSON.stringify(document.payload, null, 2));
    const submit = (event: FormEvent) => { event.preventDefault(); try { setData("payload", JSON.parse(payload)); put(route("clinicaldocumentation.update", document.id)); } catch { alert("Payload must be valid JSON."); } };
    return <AuthenticatedLayout><Content><Head title="Edit clinical draft" /><h1 className="text-2xl font-semibold">Edit private draft</h1>
        <form className="mt-6 max-w-2xl space-y-4" onSubmit={submit}><textarea className="h-80 w-full rounded border p-2 font-mono" value={payload} onChange={(e) => setPayload(e.target.value)} /><button className="rounded bg-primary px-4 py-2 text-primary-foreground" disabled={processing}>Save draft</button></form>
    </Content></AuthenticatedLayout>;
}
