import { Head, router } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useState } from "react";
import { FileUp, ShieldCheck } from "lucide-react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import { Textarea } from "@/Components/ui/textarea";
import { Label } from "@/Components/ui/label";

type Evidence = {
    id: string;
    claim: string;
    staged_by_name: string;
    staged_at: string;
    original_filename: string | null;
    file_url: string;
    can_open_file: boolean;
};

type CreateProps = {
    registrationId: string;
    evidence: Evidence[];
};

const EvidenceForm = ({ registrationId }: { registrationId: string }) => {
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [submissionError, setSubmissionError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSubmissionError(null);
        router.post(route("clinicaldocumentation.presented-external-evidence.store"), new FormData(event.currentTarget as HTMLFormElement), {
            onError: (validationErrors) => setSubmissionError(Object.values(validationErrors).join(" ")),
        });
    };

    return <form className="space-y-4 p-5" method="post" encType="multipart/form-data" onSubmit={submit}>
        <input type="hidden" name="registration_id" value={registrationId} />
        <div>
            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Registration evidence</p>
            <h2 className="mt-2 font-semibold text-foreground">Stage patient-provided evidence</h2>
        </div>
        <div className="space-y-2">
            <Label htmlFor="claim">What the patient says this file is</Label>
            <Textarea id="claim" name="claim" required placeholder="Patient says this is an external laboratory report..." />
        </div>
        <div className="space-y-2">
            <Label htmlFor="file">Upload file or scan</Label>
            <Input id="file" name="file" required type="file" onChange={(event) => setSelectedFile(event.target.files?.[0] ?? null)} />
            {selectedFile && <p className="text-sm text-muted-foreground">Selected: {selectedFile.name}</p>}
            <p className="text-xs text-muted-foreground">This stores custody facts only. A clinician decides what, if anything, belongs in the clinical document.</p>
        </div>
        {submissionError && <p id="staging-error" className="text-sm text-destructive">{submissionError}</p>}
        <Button id="stage-external-evidence" type="submit" className="w-full"><FileUp className="mr-1 size-4" />Stage external evidence</Button>
    </form>;
};

const Create = ({ registrationId, evidence }: CreateProps) => <>
    <Head title="Stage external evidence" />
    <div className="space-y-6">
        <Card>
            <CardHeader>
                <ShieldCheck className="size-5 text-primary" />
                <CardTitle>Custody staging</CardTitle>
                <CardDescription>Registration <code className="rounded bg-muted px-2 py-1 text-xs">{registrationId}</code>. Staging does not interpret, verify, or incorporate the file.</CardDescription>
            </CardHeader>
            <CardContent><EvidenceForm registrationId={registrationId} /></CardContent>
        </Card>
        <Card>
            <CardHeader>
                <CardTitle>Unreviewed evidence</CardTitle>
                <CardDescription>Only a clinician authoring this registration can review these items.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                {evidence.length === 0 ? <p className="text-sm text-muted-foreground">No evidence has been staged yet.</p> : evidence.map((item) => <div key={item.id} className="rounded-md border p-4" data-testid="staged-external-evidence">
                    <p className="font-medium">{item.claim}</p>
                    <p className="mt-1 text-sm text-muted-foreground">{item.original_filename ?? "Uploaded file"} · staged by {item.staged_by_name}</p>
                    {item.can_open_file && route().has("clinicaldocumentation.presented-external-evidence.file") && <Button asChild className="mt-3" size="sm" variant="outline"><a href={item.file_url}>Open staged file</a></Button>}
                </div>)}
            </CardContent>
        </Card>
    </div>
</>;

Create.layout = (page: ReactNode) => <AuthenticatedLayout header="Stage external evidence">
    <Content description="Stage a patient-provided file during an active Hospital Registration for a clinician to review during authoring.">
        {page}
    </Content>
</AuthenticatedLayout>;

export default Create;
