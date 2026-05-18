import { ReactNode } from "react";
import { Head, Link } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import SoapEditorForm from "../Components/SoapEditorForm";
import { Button } from "@/Components/ui/button";
import { ChevronLeft, Info } from "lucide-react";

interface Registration {
    id: string;
    type: string;
    registration_number: string;
    patient: {
        id: string;
        full_name: string;
        nocm: string;
        gender: string;
        dob: string;
    };
    doctor: { name: string };
    department: { name: string };
}

interface PatientDocument {
    id: string;
    document_name: string;
    document_type: string;
    file_path: string;
    created_at: string;
}

interface SoapNote {
    id: string;
    registration_id: string;
    status: string;
    subjective: string | null;
    objective: string | null;
    assessment: string | null;
    plan: string | null;
    vitals: Record<string, any> | null;
    noted_at: string;
    prescriptions?: any[];
}

interface Props {
    registration: Registration;
    patientDocuments: PatientDocument[];
    note: SoapNote;
}

export default function Edit({ registration, patientDocuments, note }: Props) {
    return (
        <Content>
            <Head title={`Edit Draft EMR Note - ${registration.patient.full_name}`} />
            
            <div className="max-w-7xl mx-auto space-y-6 pb-12">
                
                {/* Back / Title */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="outline" size="icon" className="h-9 w-9 rounded-xl">
                            <Link href={route("clinicaldocumentation.index")}>
                                <ChevronLeft className="w-5 h-5" />
                            </Link>
                        </Button>
                        <div>
                            <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2">
                                Edit Draft Encounter Note
                            </h1>
                            <p className="text-xs text-muted-foreground">Modify draft clinical findings before submission finalize.</p>
                        </div>
                    </div>

                    <div className="text-xs bg-amber-500/10 border border-amber-500/20 text-amber-500 font-bold px-3 py-1.5 rounded-xl uppercase flex items-center gap-1.5">
                        <Info className="w-4 h-4" /> Mode: Draft Note
                    </div>
                </div>

                {/* Shared Editor Form in EDIT mode */}
                <SoapEditorForm 
                    registration={registration} 
                    patientDocuments={patientDocuments} 
                    note={note}
                />

            </div>
        </Content>
    );
}

Edit.layout = (page: ReactNode) => (
    <AuthenticatedLayout children={page} header="EMR Patient Encounter" />
);
