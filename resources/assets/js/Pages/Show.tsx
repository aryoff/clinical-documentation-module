import { ReactNode } from "react";
import { Head, Link, router } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { 
    ChevronLeft, 
    Printer, 
    Edit, 
    Activity, 
    Clock, 
    History,
    Shield,
    ArrowRight
} from "lucide-react";
import { format } from "date-fns";

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

interface SoapNote {
    id: string;
    registration_id: string;
    status: "draft" | "submitted" | "superseded";
    author_role: string;
    subjective: string | null;
    objective: string | null;
    assessment: string | null;
    plan: string | null;
    vitals: Record<string, any> | null;
    noted_at: string;
    submitted_at: string | null;
    amended_from_id?: string | null;
    author: {
        name: string;
    };
}

interface Props {
    note: SoapNote & { registration: Registration };
    chain: SoapNote[];
}

export default function Show({ note, chain }: Props) {
    const registration = note.registration;
    const isLatestActive = note.status === "submitted" && !chain.some(n => n.amended_from_id === note.id);

    const handlePrint = () => {
        window.print();
    };

    const handleCreateAmendment = () => {
        if (confirm("Are you sure you want to create an amendment for this clinical note? This will copy current note details into a new draft addendum.")) {
            router.post(route("clinicaldocumentation.amend", note.id));
        }
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case "draft":
                return <Badge variant="outline" className="text-amber-500 border-amber-200 bg-amber-50/50 uppercase font-bold text-[10px]">Draft</Badge>;
            case "submitted":
                return <Badge variant="default" className="bg-emerald-500 hover:bg-emerald-600 uppercase font-bold text-[10px]">Finalized</Badge>;
            case "superseded":
                return <Badge variant="secondary" className="bg-slate-400 text-white uppercase font-bold text-[10px]">Superseded</Badge>;
            default:
                return <Badge variant="outline">{status}</Badge>;
        }
    };

    return (
        <Content>
            <Head title={`Clinical SOAP Report - ${registration.patient.full_name}`} />
            
            <div className="max-w-4xl mx-auto space-y-6 pb-12 print:p-0 print:pb-0 print:max-w-full">
                
                {/* Print Styles */}
                <style>{`
                    @media print {
                        body {
                            background-color: white !important;
                            color: black !important;
                        }
                        .no-print {
                            display: none !important;
                        }
                        .print-border {
                            border: 1px solid #e2e8f0 !important;
                            border-radius: 0.5rem !important;
                            box-shadow: none !important;
                        }
                    }
                `}</style>

                {/* Back / Action Bar */}
                <div className="flex justify-between items-center no-print">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="outline" size="icon" className="h-9 w-9 rounded-xl">
                            <Link href={route("clinicaldocumentation.index")}>
                                <ChevronLeft className="w-5 h-5" />
                            </Link>
                        </Button>
                        <div>
                            <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2">
                                Clinical EMR Report
                            </h1>
                            <p className="text-xs text-muted-foreground">Certified clinical encounter record and historical timeline.</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button onClick={handlePrint} variant="outline" className="font-bold gap-2">
                            <Printer className="w-4 h-4" /> Print Report
                        </Button>

                        {isLatestActive && (
                            <Button 
                                onClick={handleCreateAmendment}
                                className="bg-primary hover:bg-primary/95 text-primary-foreground font-bold gap-2"
                            >
                                <Edit className="w-4 h-4" /> Create Amendment
                            </Button>
                        )}
                    </div>
                </div>

                {/* Main Medical Report Sheet */}
                <div className="bg-card rounded-2xl border border-border shadow-sm overflow-hidden print-border">
                    {/* Header Banner */}
                    <div className="border-b bg-muted/30 p-6 md:p-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                        <div className="space-y-3">
                            <div className="flex items-center gap-3">
                                <Badge variant="outline" className="font-mono text-xs border-primary/20 text-primary bg-primary/5">
                                    MR: {registration.patient.nocm}
                                </Badge>
                                {getStatusBadge(note.status)}
                            </div>
                            <div>
                                <h2 className="text-2xl font-black tracking-tight text-foreground">{registration.patient.full_name}</h2>
                                <p className="text-xs text-muted-foreground mt-1 flex items-center gap-1.5">
                                    <Clock className="w-4 h-4 text-primary" /> Encounter Date: {format(new Date(note.noted_at), "PPP p")}
                                </p>
                            </div>
                        </div>

                        <div className="text-left md:text-right text-xs text-muted-foreground space-y-1">
                            <p>Physician: <strong className="text-foreground font-bold">{note.author.name}</strong></p>
                            <p>Class / Poly: <strong className="text-foreground font-bold">{registration.department.name}</strong></p>
                            <p>Encounter: <strong className="text-foreground font-bold">{registration.registration_number}</strong></p>
                        </div>
                    </div>

                    {/* Vitals Signs Grid Panel */}
                    {note.vitals && Object.values(note.vitals).some(v => v !== null && v !== "") && (
                        <div className="border-b bg-muted/10 p-6">
                            <h3 className="text-xs font-bold text-muted-foreground uppercase tracking-widest mb-4 flex items-center gap-2">
                                <Activity className="w-4 h-4 text-primary" /> Vital Signs Summary
                            </h3>
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                {note.vitals.temperature && (
                                    <div className="bg-card border rounded-xl p-3 shadow-sm">
                                        <span className="text-muted-foreground text-[10px] uppercase font-bold">Temperature</span>
                                        <p className="text-lg font-bold text-foreground mt-1">{note.vitals.temperature} °C</p>
                                    </div>
                                )}
                                {(note.vitals.systolic_bp || note.vitals.diastolic_bp) && (
                                    <div className="bg-card border rounded-xl p-3 shadow-sm">
                                        <span className="text-muted-foreground text-[10px] uppercase font-bold">Blood Pressure</span>
                                        <p className="text-lg font-bold text-foreground mt-1">{note.vitals.systolic_bp ?? "-"}/{note.vitals.diastolic_bp ?? "-"} mmHg</p>
                                    </div>
                                )}
                                {note.vitals.pulse_rate && (
                                    <div className="bg-card border rounded-xl p-3 shadow-sm">
                                        <span className="text-muted-foreground text-[10px] uppercase font-bold">Pulse Rate</span>
                                        <p className="text-lg font-bold text-foreground mt-1">{note.vitals.pulse_rate} bpm</p>
                                    </div>
                                )}
                                {note.vitals.spo2 && (
                                    <div className="bg-card border rounded-xl p-3 shadow-sm">
                                        <span className="text-muted-foreground text-[10px] uppercase font-bold">SpO2 (Oxygen)</span>
                                        <p className="text-lg font-bold text-foreground mt-1">{note.vitals.spo2} %</p>
                                    </div>
                                )}
                                {note.vitals.respiratory_rate && (
                                    <div className="bg-card border rounded-xl p-3 shadow-sm">
                                        <span className="text-muted-foreground text-[10px] uppercase font-bold">Resp Rate</span>
                                        <p className="text-lg font-bold text-foreground mt-1">{note.vitals.respiratory_rate} bpm</p>
                                    </div>
                                )}
                                {note.vitals.weight && (
                                    <div className="bg-card border rounded-xl p-3 shadow-sm">
                                        <span className="text-muted-foreground text-[10px] uppercase font-bold">Weight</span>
                                        <p className="text-lg font-bold text-foreground mt-1">{note.vitals.weight} kg</p>
                                    </div>
                                )}
                                {note.vitals.height && (
                                    <div className="bg-card border rounded-xl p-3 shadow-sm">
                                        <span className="text-muted-foreground text-[10px] uppercase font-bold">Height</span>
                                        <p className="text-lg font-bold text-foreground mt-1">{note.vitals.height} cm</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* SOAP Body */}
                    <div className="p-6 md:p-8 space-y-6">
                        {/* Subjective */}
                        <div className="space-y-2">
                            <h4 className="text-xs font-bold text-primary uppercase tracking-wider">Subjective (S)</h4>
                            <div className="text-sm leading-relaxed text-foreground bg-muted/20 border p-4 rounded-xl whitespace-pre-wrap min-h-[60px]">
                                {note.subjective || <span className="text-muted-foreground italic">No subjective data documented.</span>}
                            </div>
                        </div>

                        {/* Objective */}
                        <div className="space-y-2">
                            <h4 className="text-xs font-bold text-primary uppercase tracking-wider">Objective (O)</h4>
                            <div className="text-sm leading-relaxed text-foreground bg-muted/20 border p-4 rounded-xl whitespace-pre-wrap min-h-[60px]">
                                {note.objective || <span className="text-muted-foreground italic">No objective exam data documented.</span>}
                            </div>
                        </div>

                        {/* Assessment */}
                        <div className="space-y-2">
                            <h4 className="text-xs font-bold text-primary uppercase tracking-wider">Assessment (A)</h4>
                            <div className="text-sm leading-relaxed text-foreground bg-muted/20 border p-4 rounded-xl whitespace-pre-wrap min-h-[60px]">
                                {note.assessment || <span className="text-muted-foreground italic">No assessment data documented.</span>}
                            </div>
                        </div>

                        {/* Plan */}
                        <div className="space-y-2">
                            <h4 className="text-xs font-bold text-primary uppercase tracking-wider">Plan (P)</h4>
                            <div className="text-sm leading-relaxed text-foreground bg-muted/20 border p-4 rounded-xl whitespace-pre-wrap min-h-[60px]">
                                {note.plan || <span className="text-muted-foreground italic">No plan data documented.</span>}
                            </div>
                        </div>
                    </div>

                    {/* Footer Signature */}
                    <div className="border-t bg-muted/30 p-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 text-xs text-muted-foreground">
                        <div className="flex items-center gap-2">
                            <Shield className="w-4 h-4 text-primary" />
                            <span>EMR Certified digital lock. IP audited.</span>
                        </div>
                        <div>
                            {note.submitted_at ? (
                                <span>Certified: {format(new Date(note.submitted_at), "PPP p")}</span>
                            ) : (
                                <span className="text-amber-500 font-bold">Unsubmitted Encounter Draft</span>
                            )}
                        </div>
                    </div>
                </div>

                {/* Encounter Chronological History Timeline / Amendment Chain */}
                <div className="bg-card rounded-2xl border border-border p-6 shadow-sm space-y-4 no-print">
                    <h3 className="text-sm font-bold text-foreground flex items-center gap-2">
                        <History className="w-4 h-4 text-primary" /> Chronological Amendment History
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        All amendments in this encounter documentation are tracked linearly. Superseded notes are locked and retained under EMR audit compliance rules.
                    </p>

                    <div className="relative border-l border-border pl-6 ml-3 space-y-6">
                        {chain.map((item) => (
                            <div key={item.id} className="relative group">
                                {/* Chronological node dot */}
                                <div className={`absolute -left-[31px] top-1.5 h-4 w-4 rounded-full border-2 bg-card ${
                                    item.id === note.id 
                                    ? "border-primary bg-primary scale-125 shadow-sm" 
                                    : "border-border hover:border-primary transition-all"
                                }`}></div>

                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                    <div className="text-xs">
                                        <p className="font-bold text-foreground flex items-center gap-2">
                                            Clinical encounter record by {item.author.name}
                                            {item.id === note.id && <span className="text-[10px] text-primary font-bold">(Viewing)</span>}
                                        </p>
                                        <p className="text-muted-foreground mt-0.5">
                                            Encounter logged {format(new Date(item.noted_at), "PP p")}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-2 shrink-0">
                                        {getStatusBadge(item.status)}
                                        {item.id !== note.id && (
                                            <Button asChild size="sm" variant="outline" className="h-7 text-xs font-bold gap-1">
                                                <Link href={route("clinicaldocumentation.show", item.id)}>
                                                    View Record <ArrowRight className="w-3.5 h-3.5" />
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

            </div>
        </Content>
    );
}

Show.layout = (page: ReactNode) => (
    <AuthenticatedLayout children={page} header="Certified EMR Documentation" />
);
