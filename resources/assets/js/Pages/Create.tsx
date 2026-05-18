import { ReactNode } from "react";
import { Head, Link } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import SoapEditorForm from "../Components/SoapEditorForm";
import { Button } from "@/Components/ui/button";
import { ChevronLeft } from "lucide-react";

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

interface Props {
    registration: Registration;
    patientDocuments: PatientDocument[];
}

export default function Create({ registration, patientDocuments }: Props) {
    return (
        <Content>
            <Head title={`New EMR Note - ${registration.patient.full_name}`} />
            
            <div className="max-w-7xl mx-auto space-y-6 pb-12">
                
                {/* Back to list */}
                <div className="flex items-center gap-3">
                    <Button asChild variant="outline" size="icon" className="h-9 w-9 rounded-xl">
                        <Link href={route("clinicaldocumentation.index")}>
                            <ChevronLeft className="w-5 h-5" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-xl font-bold tracking-tight text-foreground">New EMR Encounter Note</h1>
                        <p className="text-xs text-muted-foreground">Formulating clinical SOAP documentation for patient visit.</p>
                    </div>
                </div>

                {/* Shared Editor Form in CREATE mode */}
                <SoapEditorForm 
                    registration={registration} 
                    patientDocuments={patientDocuments} 
                />

            </div>
        </Content>
    );
}

Create.layout = (page: ReactNode) => (
    <AuthenticatedLayout children={page} header="EMR Patient Encounter" />
);
