import { FormEvent, useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

type ClinicalDocumentForm = { handoff_id: string; template: string; template_version: string; encountered_at: string; payload: Record<string, any> };

export default function Create({ handoffId }: { handoffId: string }) {
    const { data, post, processing, setData, errors } = useForm<ClinicalDocumentForm>({ handoff_id: handoffId, template: "soap", template_version: "1.0.0", encountered_at: new Date().toISOString(), payload: {} });
    const [payload, setPayload] = useState('{\n  "subjective": ""\n}');
    const submit = (event: FormEvent) => { event.preventDefault(); try { setData("payload", JSON.parse(payload)); post(route("clinicaldocumentation.store")); } catch { alert("Payload must be valid JSON."); } };
    return <AuthenticatedLayout><Content><Head title="New clinical document" />
        <h1 className="text-2xl font-semibold">New clinical document</h1><p className="mt-2 text-sm text-muted-foreground">Accepted handoff: {handoffId}</p>
        <form className="mt-6 max-w-2xl space-y-4" onSubmit={submit}>
            <label className="block text-sm font-medium">Template<input className="mt-1 w-full rounded border p-2" value={data.template} onChange={(e) => setData("template", e.target.value)} /></label>
            <label className="block text-sm font-medium">Template version<input className="mt-1 w-full rounded border p-2" value={data.template_version} onChange={(e) => setData("template_version", e.target.value)} /></label>
            <label className="block text-sm font-medium">Clinical payload<textarea className="mt-1 h-64 w-full rounded border p-2 font-mono" value={payload} onChange={(e) => setPayload(e.target.value)} /></label>
            {Object.values(errors).map((error) => <p key={String(error)} className="text-sm text-destructive">{String(error)}</p>)}
            <button className="rounded bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50" disabled={processing}>Create private draft</button>
        </form>
    </Content></AuthenticatedLayout>;
}
