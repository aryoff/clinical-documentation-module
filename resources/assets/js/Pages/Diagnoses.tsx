import { Head, Link } from "@inertiajs/react";
import { Stethoscope } from "lucide-react";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/Components/ui/card";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import { type DiagnosisLineage, DiagnosisLineageView } from "../Components/DiagnosisLineage";

const Diagnoses = ({ lineage }: { lineage: DiagnosisLineage }) => <AuthenticatedLayout header="Clinical diagnosis history">
    <Head title="Clinical diagnosis history" />
    <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">What this patient has been diagnosed with, and when it changed. Signed notes are not included.</p>
        {route().has("clinicaldocumentation.index") && <Button asChild variant="outline">
            <Link href={route("clinicaldocumentation.index")}>Back to documents</Link>
        </Button>}
    </div>
    <Card className="mb-4">
        <CardHeader>
            <Stethoscope className="size-5 text-primary" />
            <CardTitle className="text-base">Current diagnoses</CardTitle>
            <CardDescription>The assertions a new order may cite. A superseded diagnosis stays readable below but can no longer justify one.</CardDescription>
        </CardHeader>
        <CardContent>
            {lineage.current.length === 0
                ? <p className="text-sm text-muted-foreground">None recorded.</p>
                : <ul className="space-y-1" id="current_diagnoses">
                    {lineage.current.map((assertion) => <li key={assertion.assertion_id} className="text-sm">
                        <span className="font-mono font-semibold">{assertion.code}</span> — {assertion.display}
                    </li>)}
                </ul>}
        </CardContent>
    </Card>
    <DiagnosisLineageView lineage={lineage} />
</AuthenticatedLayout>;

export default Diagnoses;
