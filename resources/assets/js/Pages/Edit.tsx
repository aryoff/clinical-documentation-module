import { FormEvent, useEffect, useRef, useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import { FilePenLine } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import { Button } from "@/Components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";

type Document = { id: string; payload: Record<string, unknown>; encountered_at: string };
type ClinicalDocumentForm = { payload: Record<string, any>; encountered_at: string };
export default function Edit({ document }: { document: Document }) {
    const panel = useRef<ActionBarHandle>(null); useEffect(() => panel.current?.expand(), []);
    const { put, processing, setData } = useForm<ClinicalDocumentForm>({ payload: document.payload, encountered_at: document.encountered_at });
    const [payload, setPayload] = useState(JSON.stringify(document.payload, null, 2));
    const submit = (event: FormEvent) => { event.preventDefault(); try { setData("payload", JSON.parse(payload)); put(route("clinicaldocumentation.update", document.id)); } catch { alert("Payload must be valid JSON."); } };
    const form = <form className="space-y-4 p-5" onSubmit={submit}><div><p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Private draft</p><h2 className="mt-2 font-semibold text-foreground">Continue authoring</h2></div><div className="space-y-2"><Label htmlFor="payload">Clinical payload</Label><Textarea id="payload" className="min-h-80 font-mono text-xs" value={payload} onChange={(e) => setPayload(e.target.value)} /></div><Button className="w-full" disabled={processing}>Save draft</Button></form>;
    return <AuthenticatedLayout header="Edit clinical draft"><Head title="Edit clinical draft" /><Content actionBar={form} actionBarRef={panel}><p className="mb-6 text-sm text-muted-foreground">Drafts remain private to their author. Signing locks this source document permanently; later clarification is a separate addendum.</p><Card><CardHeader><FilePenLine className="size-5 text-primary" /><CardTitle>Draft protection</CardTitle><CardDescription>Only you can update this draft. Its recorded encounter time remains {new Date(document.encountered_at).toLocaleString()}.</CardDescription></CardHeader></Card></Content></AuthenticatedLayout>;
}
