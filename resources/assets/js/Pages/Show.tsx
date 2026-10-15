import { Head, Link, router } from "@inertiajs/react";
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from "react";
import { Archive, FileLock2, History, ShieldCheck, Stethoscope } from "lucide-react";
import { type ActionBarHandle } from "@/Components/ActionBar";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { type DiagnosisLineage, DiagnosisLineageView, selectClasses } from "../Components/DiagnosisLineage";
import { useModuleT } from "@/hooks/useModuleT";

const openAddendumPanel = "clinicaldocumentation:open-addendum-panel";

type Document = {
    document_id: string;
    template: string;
    template_version: string;
    status: string;
    payload: Record<string, unknown>;
    encountered_at: string;
    signed_at: string | null;
};
type ShowProps = {
    document: Document;
    immutabilityNotice?: string | null;
    canArchive?: boolean;
    canAssertDiagnosis?: boolean;
    diagnoses: DiagnosisLineage;
};

/**
 * Naming the diagnosis is a separate act from signing the note, and it appends
 * rather than edits: an `initial` opens the care journey, a `supplement` adds a
 * second concurrent finding, and a `supersession` corrects one that is still
 * current. The supersession target list therefore offers heads only — a
 * revision that has already been corrected is not a thing to correct again.
 */
function DiagnosisPanel({ document, diagnoses, canAssert }: { document: Document; diagnoses: DiagnosisLineage; canAssert: boolean }) {
  const { t } = useModuleT();
    const heads = diagnoses.current;
    const [chosenType, setChosenType] = useState("initial");
    // Derived, not merely initialised. Which kinds are on offer depends on
    // whether a head exists, and that changes underneath this component the
    // moment the first assertion lands. A state value left holding `initial`
    // would select an option that is no longer rendered — a blank control that
    // submits a refusal.
    const assertionType = heads.length === 0
        ? "initial"
        : chosenType === "initial" ? "supersession" : chosenType;
    const [code, setCode] = useState("");
    const [display, setDisplay] = useState("");
    const [note, setNote] = useState("");
    const [supersedes, setSupersedes] = useState("");

    const assert = (event: FormEvent) => {
        event.preventDefault();
        router.post(route("clinicaldocumentation.diagnoses.assert", document.document_id), {
            coding_system: "ICD-10",
            code,
            display,
            assertion_type: assertionType,
            note: note === "" ? null : note,
            supersedes_assertion_id: assertionType === "supersession" ? supersedes : null,
        });
    };

    return <Card className={"mt-4"} id={"diagnosis_panel"}>
        <CardHeader>
            <Stethoscope className={"size-5 text-primary"} />
            <CardTitle className={"text-base"}>{t("show.diagnosis")}</CardTitle>
            <CardDescription>{t("show.what_this_signed_document")}</CardDescription>
        </CardHeader>
        <CardContent className={"space-y-6"}>
            <DiagnosisLineageView lineage={diagnoses} />
            {canAssert && <form className={"grid gap-4 border-t pt-6 md:grid-cols-2"} onSubmit={assert}>
                <div className={"space-y-2"}>
                    <Label htmlFor={"assertion_type"}>{t("show.kind_of_assertion")}</Label>
                    <select id={"assertion_type"} className={selectClasses} value={assertionType} onChange={(event) => setChosenType(event.target.value)}>
                        {heads.length === 0 && <option value={"initial"}>{t("show.initial_diagnosis")}</option>}
                        {heads.length > 0 && <option value={"supplement"}>{t("show.additional_diagnosis")}</option>}
                        {heads.length > 0 && <option value={"supersession"}>{t("show.correct_a_current_diagnosis")}</option>}
                    </select>
                </div>
                {assertionType === "supersession" && <div className={"space-y-2"}>
                    <Label htmlFor={"supersedes_assertion_id"}>{t("show.diagnosis_being_corrected")}</Label>
                    <select id={"supersedes_assertion_id"} className={selectClasses} value={supersedes} onChange={(event) => setSupersedes(event.target.value)} required>
                        <option value={""}>{t("show.select_the_diagnosis_to")}</option>
                        {heads.map((head) => <option key={head.assertion_id} value={head.assertion_id}>{t("shared.code_display", { code: head.code, display: head.display })}</option>)}
                    </select>
                </div>}
                <div className={"space-y-2"}>
                    <Label htmlFor={"diagnosis_code"}>{t("show.icd_10_code")}</Label>
                    <Input id={"diagnosis_code"} required placeholder={t("show.j02_9")} value={code} onChange={(event) => setCode(event.target.value)} />
                </div>
                <div className={"space-y-2"}>
                    <Label htmlFor={"diagnosis_display"}>{t("show.diagnosis")}</Label>
                    <Input id={"diagnosis_display"} required placeholder={t("show.acute_pharyngitis_unspecified")} value={display} onChange={(event) => setDisplay(event.target.value)} />
                </div>
                <div className={"space-y-2 md:col-span-2"}>
                    <Label htmlFor={"diagnosis_note"}>{t("show.clinical_reasoning_optional")}</Label>
                    <Textarea id={"diagnosis_note"} className={"min-h-20"} value={note} onChange={(event) => setNote(event.target.value)} />
                </div>
                <div className={"md:col-span-2"}>
                    <Button id={"record_diagnosis"} className={"w-full"}>{t("show.record_diagnosis")}</Button>
                </div>
            </form>}
        </CardContent>
    </Card>;
}

function AddendumActionBar({ document }: Pick<ShowProps, "document">) {
  const { t } = useModuleT();
    const [reason, setReason] = useState("");
    const [payload, setPayload] = useState("{}");

    const amend = (event: FormEvent) => {
        event.preventDefault();

        try {
            router.post(route("clinicaldocumentation.amend", document.document_id), {
                reason,
                payload: JSON.parse(payload),
                encountered_at: new Date().toISOString(),
            });
        } catch {
            alert(t("show.addendum_payload_must_be"));
        }
    };

    return <form className={"space-y-4 p-5"} onSubmit={amend}>
        <div>
            <p className={"text-xs font-semibold tracking-wider text-muted-foreground uppercase"}>{t("show.linked_correction")}</p>
            <h2 className={"mt-2 font-semibold text-foreground"}>{t("show.signed_addendum")}</h2>
        </div>
        <p className={"text-sm text-muted-foreground"}>{t("show.an_addendum_preserves_this")}</p>
        <div className={"space-y-2"}>
            <Label htmlFor={"reason"}>{t("show.reason")}</Label>
            <Input id={"reason"} required placeholder={t("show.clarification_or_correction_reason")} value={reason} onChange={(event) => setReason(event.target.value)} />
        </div>
        <div className={"space-y-2"}>
            <Label htmlFor={"addendum-payload"}>{t("show.addendum_payload")}</Label>
            <Textarea id={"addendum-payload"} className={"min-h-48 font-mono text-xs"} value={payload} onChange={(event) => setPayload(event.target.value)} />
        </div>
        <Button className={"w-full"}>{t("show.record_signed_addendum")}</Button>
    </form>;
}

function ShowLayout({ page, document }: { page: ReactNode; document: Document }) {
    const panel = useRef<ActionBarHandle>(null);

    useEffect(() => {
        const open = () => panel.current?.expand();

        window.addEventListener(openAddendumPanel, open);

        return () => window.removeEventListener(openAddendumPanel, open);
    }, []);

    // Reading a signed document and amending one are separate authorities, and
    // Break-Glass buys a read and never a write. Without the amend route the
    // panel has nothing to offer, so the ActionBar is not fitted at all.
    const canAmend = route().has("clinicaldocumentation.amend");

    return <AuthenticatedLayout header={(t) => t("show.clinical_document")}>
        <Content actionBar={canAmend ? <AddendumActionBar document={document} /> : undefined} actionBarRef={panel}>{page}</Content>
    </AuthenticatedLayout>;
}

const Show = ({ document, immutabilityNotice, canArchive, canAssertDiagnosis, diagnoses }: ShowProps) => {
  const { t } = useModuleT();
  return <>
    <Head title={t("show.clinical_document")} />
    {immutabilityNotice && <Alert className={"mb-6"}>
        <FileLock2 className={"size-4"} />
        <AlertTitle>{immutabilityNotice}</AlertTitle>
        <AlertDescription>
            {t("show.signed_source_notice")}
            {route().has("clinicaldocumentation.amend") && <Button className={"mt-3"} onClick={() => window.dispatchEvent(new Event(openAddendumPanel))}>{t("show.record_an_addendum_instead")}</Button>}
        </AlertDescription>
    </Alert>}
    <div className={"mb-6 flex flex-wrap items-center justify-between gap-3"}>
        <p className={"text-sm text-muted-foreground"}>{t("show.signed_evidence_for_this")}</p>
        <div className={"flex flex-wrap gap-2"}>
            <Button asChild variant={"outline"}><Link href={route("clinicaldocumentation.index")}>{t("show.back_to_documents")}</Link></Button>
            {route().has("clinicaldocumentation.audit") && <Button asChild variant={"outline"}>
                <Link href={route("clinicaldocumentation.audit", { document_id: document.document_id })}>{t("show.access_audit")}</Link>
            </Button>}
            {canArchive && <Button variant={"outline"} onClick={() => router.post(route("clinicaldocumentation.archive", document.document_id))}>
                <Archive className={"mr-1 size-4"} />{t("show.request_archive")}</Button>}
            {route().has("clinicaldocumentation.diagnoses.index") && <Button asChild variant={"outline"}>
                <Link href={route("clinicaldocumentation.diagnoses.index", diagnoses.patient_id)}>{t("show.diagnosis_history")}</Link>
            </Button>}
            {route().has("clinicaldocumentation.amend") && <Button onClick={() => window.dispatchEvent(new Event(openAddendumPanel))}>{t("show.add_addendum")}</Button>}
        </div>
    </div>
    <Card>
        <CardHeader>
            <div className={"flex items-start justify-between gap-4"}>
                <div>
                    <CardTitle>{document.template} <span className={"text-muted-foreground"}>{t("shared.template_version", { version: document.template_version })}</span></CardTitle>
                    <CardDescription className={"mt-2"}>{t("shared.encountered_signed", { encountered: new Date(document.encountered_at).toLocaleString(), signed: document.signed_at ? new Date(document.signed_at).toLocaleString() : t("show.not_yet") })}</CardDescription>
                </div>
                <Badge><FileLock2 className={"mr-1 size-3"} />{t(`shared.document_status.${document.status}`)}</Badge>
            </div>
        </CardHeader>
        <CardContent><pre className={"overflow-auto rounded-md border bg-muted/40 p-4 text-sm"}>{JSON.stringify(document.payload, null, 2)}</pre></CardContent>
    </Card>
    <DiagnosisPanel document={document} diagnoses={diagnoses} canAssert={canAssertDiagnosis === true && route().has("clinicaldocumentation.diagnoses.assert")} />
    <div className={"mt-4 grid gap-4 md:grid-cols-2"}>
        <Card><CardHeader><ShieldCheck className={"size-5 text-primary"} /><CardTitle className={"text-base"}>{t("show.immutable_source")}</CardTitle><CardDescription>{t("show.access_emergency_use_and")}</CardDescription></CardHeader></Card>
        <Card><CardHeader><History className={"size-5 text-primary"} /><CardTitle className={"text-base"}>{t("show.correction_history")}</CardTitle><CardDescription>{t("show.use_the_action_panel")}</CardDescription></CardHeader></Card>
    </div>
</>;
};

Show.layout = (page: ReactNode) => {
    const props = ((page as { props?: ShowProps }).props ?? page) as ShowProps;

    return <ShowLayout page={page} document={props.document} />;
};

export default Show;
