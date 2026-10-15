import { Head, Link } from "@inertiajs/react";
import { Stethoscope } from "lucide-react";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import { type DiagnosisLineage, DiagnosisLineageView } from "../Components/DiagnosisLineage";
import { useModuleT } from "@/hooks/useModuleT";

const Diagnoses = ({ lineage }: { lineage: DiagnosisLineage }) => {
  const { t } = useModuleT();
  return <AuthenticatedLayout header={(t) => t("diagnoses.clinical_diagnosis_history")}>
    <Head title={t("diagnoses.clinical_diagnosis_history")} />
    <div className={"mb-6 flex flex-wrap items-center justify-between gap-3"}>
        <p className={"text-sm text-muted-foreground"}>{t("diagnoses.what_this_patient_has")}</p>
        {route().has("clinicaldocumentation.index") && <Button asChild variant={"outline"}>
            <Link href={route("clinicaldocumentation.index")}>{t("diagnoses.back_to_documents")}</Link>
        </Button>}
    </div>
    <Card className={"mb-4"}>
        <CardHeader>
            <Stethoscope className={"size-5 text-primary"} />
            <CardTitle className={"text-base"}>{t("diagnoses.current_diagnoses")}</CardTitle>
            <CardDescription>{t("diagnoses.the_assertions_a_new")}</CardDescription>
        </CardHeader>
        <CardContent>
            {lineage.current.length === 0
                ? <p className={"text-sm text-muted-foreground"}>{t("diagnoses.none_recorded")}</p>
                : <ul className={"space-y-1"} id={"current_diagnoses"}>
                    {lineage.current.map((assertion) => <li key={assertion.assertion_id} className={"text-sm"}>
                        <span className={"font-mono font-semibold"}>{assertion.code}</span>{t("shared.dash_display", { display: assertion.display })}
                    </li>)}
                </ul>}
        </CardContent>
    </Card>
    <DiagnosisLineageView lineage={lineage} />
</AuthenticatedLayout>;
};

export default Diagnoses;
