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
import { useModuleT } from "@/hooks/useModuleT";

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
  const { t } = useModuleT();
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [submissionError, setSubmissionError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSubmissionError(null);
        router.post(route("clinicaldocumentation.presented-external-evidence.store"), new FormData(event.currentTarget as HTMLFormElement), {
            onError: (validationErrors) => setSubmissionError(Object.values(validationErrors).join(" ")),
        });
    };

    return <form className={"space-y-4 p-5"} method={"post"} encType={"multipart/form-data"} onSubmit={submit}>
        <input type={"hidden"} name={"registration_id"} value={registrationId} />
        <div>
            <p className={"text-xs font-semibold tracking-wider text-muted-foreground uppercase"}>{t("create.registration_evidence")}</p>
            <h2 className={"mt-2 font-semibold text-foreground"}>{t("create.stage_patient_provided_evidence")}</h2>
        </div>
        <div className={"space-y-2"}>
            <Label htmlFor={"claim"}>{t("create.what_the_patient_says")}</Label>
            <Textarea id={"claim"} name={"claim"} required placeholder={t("create.patient_says_this_is")} />
        </div>
        <div className={"space-y-2"}>
            <Label htmlFor={"file"}>{t("create.upload_file_or_scan")}</Label>
            <Input id={"file"} name={"file"} required type={"file"} onChange={(event) => setSelectedFile(event.target.files?.[0] ?? null)} />
            {selectedFile && <p className={"text-sm text-muted-foreground"}>{t("create.selected_file", { name: selectedFile.name })}</p>}
            <p className={"text-xs text-muted-foreground"}>{t("create.this_stores_custody_facts")}</p>
        </div>
        {submissionError && <p id={"staging-error"} className={"text-sm text-destructive"}>{submissionError}</p>}
        <Button id={"stage-external-evidence"} type={"submit"} className={"w-full"}><FileUp className={"mr-1 size-4"} />{t("create.stage_external_evidence")}</Button>
    </form>;
};

const Create = ({ registrationId, evidence }: CreateProps) => {
  const { t } = useModuleT();
  return <>
    <Head title={t("create.stage_external_evidence")} />
    <div className={"space-y-6"}>
        <Card>
            <CardHeader>
                <ShieldCheck className={"size-5 text-primary"} />
                <CardTitle>{t("create.custody_staging")}</CardTitle>
                <CardDescription>{t("create.registration_label")} <code className={"rounded bg-muted px-2 py-1 text-xs"}>{registrationId}</code>{t("create.staging_note")}</CardDescription>
            </CardHeader>
            <CardContent><EvidenceForm registrationId={registrationId} /></CardContent>
        </Card>
        <Card>
            <CardHeader>
                <CardTitle>{t("create.unreviewed_evidence")}</CardTitle>
                <CardDescription>{t("create.only_a_clinician_authoring")}</CardDescription>
            </CardHeader>
            <CardContent className={"space-y-3"}>
                {evidence.length === 0 ? <p className={"text-sm text-muted-foreground"}>{t("create.no_evidence_has_been")}</p> : evidence.map((item) => <div key={item.id} className={"rounded-md border p-4"} data-testid={"staged-external-evidence"}>
                    <p className={"font-medium"}>{item.claim}</p>
                    <p className={"mt-1 text-sm text-muted-foreground"}>{t("shared.staged_by_line", { file: item.original_filename ?? t("create.uploaded_file"), name: item.staged_by_name })}</p>
                    {item.can_open_file && route().has("clinicaldocumentation.presented-external-evidence.file") && <Button asChild className={"mt-3"} size={"sm"} variant={"outline"}><a href={item.file_url}>{t("create.open_staged_file")}</a></Button>}
                </div>)}
            </CardContent>
        </Card>
    </div>
</>;
};

const CreateContent = ({ children }: { children: ReactNode }) => {
    const { t } = useModuleT();
    return <Content description={t("create.stage_a_patient_provided")}>{children}</Content>;
};

Create.layout = (page: ReactNode) => <AuthenticatedLayout header={(t) => t("create.stage_external_evidence")}>
    <CreateContent>{page}</CreateContent>
</AuthenticatedLayout>;

export default Create;
