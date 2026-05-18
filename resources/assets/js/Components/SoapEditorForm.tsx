import { useState, useEffect } from "react";
import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { Textarea } from "@/Components/ui/textarea";
import { Label } from "@/Components/ui/label";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/Components/ui/tabs";
import { 
    Table, 
    TableBody, 
    TableCell, 
    TableHead, 
    TableHeader, 
    TableRow 
} from "@/Components/ui/table";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter
} from "@/Components/ui/dialog";
import { 
    Save, 
    Activity, 
    Search, 
    Plus, 
    Trash2, 
    AlertTriangle, 
    Lock, 
    Loader2, 
    FileImage,
    Maximize2,
    CheckCircle
} from "lucide-react";
import PrescriptionPanel from "@Modules/EPrescription/resources/assets/js/Components/PrescriptionPanel";

const ShadButton = Button;

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
    document_type: string; // 'lab', 'radiology', 'other'
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
    note?: SoapNote; // undefined in create mode, present in edit mode
}

interface DiagnosisInput {
    icd_code_id: string;
    code: string;
    description: string;
    diagnosis_type: "primary" | "secondary";
    notes: string;
}

interface IcdResult {
    id: string;
    code: string;
    description: string;
}

export default function SoapEditorForm({ registration, patientDocuments, note }: Props) {
    const isEdit = !!note;
    const [activeTab, setActiveTab] = useState<string>("subjective");
    const [icdQuery, setIcdQuery] = useState("");
    const [icdResults, setIcdResults] = useState<IcdResult[]>([]);
    const [isSearchingIcd, setIsSearchingIcd] = useState(false);
    
    // Finalization / Diagnosis Dialog
    const [isSubmitDialogOpen, setIsSubmitDialogOpen] = useState(false);
    const [diagnoses, setDiagnoses] = useState<DiagnosisInput[]>([]);
    const [submittingNote, setSubmittingNote] = useState(false);

    // Document Preview
    const [previewDoc, setPreviewDoc] = useState<PatientDocument | null>(null);

    // Initialize core form via useForm
    const { data, setData, post, put, processing, errors } = useForm({
        registration_id: registration.id,
        subjective: note?.subjective || "",
        objective: note?.objective || "",
        assessment: note?.assessment || "",
        plan: note?.plan || "",
        vitals: {
            temperature: note?.vitals?.temperature ?? "",
            systolic_bp: note?.vitals?.systolic_bp ?? "",
            diastolic_bp: note?.vitals?.diastolic_bp ?? "",
            pulse_rate: note?.vitals?.pulse_rate ?? "",
            spo2: note?.vitals?.spo2 ?? "",
            respiratory_rate: note?.vitals?.respiratory_rate ?? "",
            weight: note?.vitals?.weight ?? "",
            height: note?.vitals?.height ?? "",
        },
        noted_at: note?.noted_at ? new Date(note.noted_at).toISOString().slice(0, 16) : new Date().toISOString().slice(0, 16),
    });

    // Physiologic bounds warning & validation state
    const [vitalsWarnings, setVitalsWarnings] = useState<Record<string, string>>({});

    const validateVitals = (key: string, val: string | number) => {
        if (val === "" || val === null || val === undefined) {
            setVitalsWarnings(prev => {
                const next = { ...prev };
                delete next[key];
                return next;
            });
            return;
        }

        const num = parseFloat(String(val));
        let warning = "";

        switch (key) {
            case "temperature":
                if (num < 30.0 || num > 45.0) warning = "Critically invalid temperature";
                else if (num < 36.0 || num > 37.5) warning = "Physiologic alert: Abnormal temp (normal: 36-37.5 °C)";
                break;
            case "systolic_bp":
                if (num < 50 || num > 250) warning = "Critically invalid systolic BP";
                else if (num < 90 || num > 140) warning = "Physiologic alert: Abnormal systolic BP (normal: 90-140)";
                break;
            case "diastolic_bp":
                if (num < 30 || num > 150) warning = "Critically invalid diastolic BP";
                else if (num < 60 || num > 90) warning = "Physiologic alert: Abnormal diastolic BP (normal: 60-90)";
                break;
            case "pulse_rate":
                if (num < 20 || num > 250) warning = "Critically invalid heart rate";
                else if (num < 60 || num > 100) warning = "Physiologic alert: Abnormal heart rate (normal: 60-100)";
                break;
            case "spo2":
                if (num < 50 || num > 100) warning = "Critically invalid SpO2 percentage";
                else if (num < 95) warning = "Physiologic alert: Hypoxia detected (SpO2 < 95%)";
                break;
            case "respiratory_rate":
                if (num < 8 || num > 60) warning = "Critically invalid respiratory rate";
                else if (num < 12 || num > 20) warning = "Physiologic alert: Abnormal respiratory rate (normal: 12-20)";
                break;
            case "weight":
                if (num < 1 || num > 500) warning = "Critically invalid weight value";
                break;
            case "height":
                if (num < 30 || num > 250) warning = "Critically invalid height value";
                break;
        }

        setVitalsWarnings(prev => {
            const next = { ...prev };
            if (warning) {
                next[key] = warning;
            } else {
                delete next[key];
            }
            return next;
        });
    };

    const handleVitalChange = (key: string, value: string) => {
        setData("vitals", {
            ...data.vitals,
            [key]: value
        });
        validateVitals(key, value);
    };

    // Live search ICD codes from API
    useEffect(() => {
        if (icdQuery.trim().length < 2) {
            setIcdResults([]);
            return;
        }

        const delayDebounceFn = setTimeout(async () => {
            setIsSearchingIcd(true);
            try {
                const response = await fetch(`/clinical-documentation/api/icd?q=${encodeURIComponent(icdQuery)}`);
                const results = await response.json();
                setIcdResults(results);
            } catch (err) {
                console.error("Failed to search ICD codes:", err);
            } finally {
                setIsSearchingIcd(false);
            }
        }, 300);

        return () => clearTimeout(delayDebounceFn);
    }, [icdQuery]);

    // Handle "Save Draft" Form Actions
    const handleSaveDraft = (e: React.FormEvent) => {
        e.preventDefault();

        // Check for any critical invalid vitals before allowing draft save
        const hasCriticalWarnings = Object.values(vitalsWarnings).some(w => w.includes("Critically invalid"));
        if (hasCriticalWarnings) {
            alert("Please correct critically invalid vital values before saving.");
            return;
        }

        if (isEdit) {
            put(route("clinicaldocumentation.update", note.id), {
                preserveScroll: true
            });
        } else {
            post(route("clinicaldocumentation.store"));
        }
    };

    // Add Diagnosis to Selected List
    const addDiagnosis = (icd: IcdResult) => {
        if (diagnoses.some(d => d.icd_code_id === icd.id)) return;

        setDiagnoses([
            ...diagnoses,
            {
                icd_code_id: icd.id,
                code: icd.code,
                description: icd.description,
                diagnosis_type: diagnoses.length === 0 ? "primary" : "secondary",
                notes: ""
            }
        ]);
        setIcdQuery("");
        setIcdResults([]);
    };

    const removeDiagnosis = (idx: number) => {
        setDiagnoses(diagnoses.filter((_, i) => i !== idx));
    };

    const updateDiagnosisType = (idx: number, type: "primary" | "secondary") => {
        const next = [...diagnoses];
        next[idx].diagnosis_type = type;
        setDiagnoses(next);
    };

    const updateDiagnosisNotes = (idx: number, text: string) => {
        const next = [...diagnoses];
        next[idx].notes = text;
        setDiagnoses(next);
    };

    // Submit & Finalize Note
    const handleConfirmFinalize = () => {
        if (!isEdit) return;

        if (diagnoses.length === 0) {
            alert("A clinical note must have at least one active diagnosis linked before submission.");
            return;
        }

        // Verify we have exactly one primary diagnosis
        const primaryCount = diagnoses.filter(d => d.diagnosis_type === "primary").length;
        if (primaryCount !== 1) {
            alert("Please select exactly one Primary Diagnosis.");
            return;
        }

        setSubmittingNote(true);
        router.post(route("clinicaldocumentation.submit", note.id), {
            diagnoses: diagnoses as any
        }, {
            onSuccess: () => {
                setIsSubmitDialogOpen(false);
                setSubmittingNote(false);
            },
            onError: () => {
                setSubmittingNote(false);
            }
        });
    };

    return (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            {/* Left Column: Form Workbench (8 cols) */}
            <form onSubmit={handleSaveDraft} className="lg:col-span-8 space-y-6">
                
                {/* Patient Glassmorphic Info Banner */}
                <div className="bg-card rounded-2xl border border-border p-5 shadow-sm flex flex-col md:flex-row justify-between items-start md:items-center gap-4 relative overflow-hidden">
                    <div className="flex items-center gap-4">
                        <div className="p-3 bg-primary/10 rounded-xl">
                            <Activity className="w-6 h-6 text-primary" />
                        </div>
                        <div>
                            <h2 className="text-xl font-bold tracking-tight text-foreground">{registration.patient.full_name}</h2>
                            <div className="flex items-center gap-2 text-xs text-muted-foreground mt-1">
                                <span className="font-semibold text-primary">MR: {registration.patient.nocm}</span>
                                <span>•</span>
                                <span>{registration.patient.gender === "L" ? "Male" : "Female"}</span>
                                <span>•</span>
                                <span>{new Date().getFullYear() - new Date(registration.patient.dob).getFullYear()} Years</span>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-4 text-xs bg-muted/40 px-4 py-2.5 rounded-xl border">
                        <div>
                            <span className="text-muted-foreground uppercase text-[10px] block font-bold">Encounter ID</span>
                            <span className="text-foreground font-mono font-bold mt-0.5">{registration.registration_number}</span>
                        </div>
                        <div className="w-px h-6 bg-border"></div>
                        <div>
                            <span className="text-muted-foreground uppercase text-[10px] block font-bold">Class</span>
                            <span className="text-foreground font-bold mt-0.5">{registration.department.name}</span>
                        </div>
                    </div>
                </div>

                {/* Tabs Configured SOAP Panel */}
                <div className="bg-card rounded-2xl border shadow-sm overflow-hidden">
                    
                    <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
                        <TabsList className="bg-muted border-b border-border rounded-none p-1.5 h-12 w-full grid grid-cols-4">
                            <TabsTrigger value="subjective" className="font-bold text-xs uppercase tracking-wider">Subjective (S)</TabsTrigger>
                            <TabsTrigger value="objective" className="font-bold text-xs uppercase tracking-wider">Objective (O)</TabsTrigger>
                            <TabsTrigger value="assessment" className="font-bold text-xs uppercase tracking-wider">Assessment (A)</TabsTrigger>
                            <TabsTrigger value="plan" className="font-bold text-xs uppercase tracking-wider">Plan (P)</TabsTrigger>
                        </TabsList>

                        {/* Subjective */}
                        <TabsContent value="subjective" className="p-6 space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="subjective" className="text-sm font-bold text-foreground">
                                    Subjective Findings
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Record details regarding chief complaints, current symptoms, duration, and clinical history as reported by the patient.
                                </p>
                                <Textarea 
                                    id="subjective"
                                    value={data.subjective}
                                    onChange={e => setData("subjective", e.target.value)}
                                    rows={8}
                                    placeholder="Enter anamnesis and subjective patient statements..."
                                    className="bg-muted/30 border-border text-foreground"
                                />
                                {errors.subjective && <p className="text-xs text-destructive">{errors.subjective}</p>}
                            </div>
                        </TabsContent>

                        {/* Objective */}
                        <TabsContent value="objective" className="p-6 space-y-6">
                            <div className="space-y-4">
                                <Label className="text-sm font-bold text-foreground block">
                                    Structured Physiologic Vital Signs
                                </Label>

                                {/* Numerical Grid */}
                                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                    {/* Temp */}
                                    <div className="space-y-1">
                                        <Label htmlFor="vitals_temp" className="text-xs font-semibold text-muted-foreground">Temp (°C)</Label>
                                        <Input 
                                            id="vitals_temp"
                                            type="number"
                                            step="0.1"
                                            value={data.vitals.temperature}
                                            onChange={e => handleVitalChange("temperature", e.target.value)}
                                            className={`bg-muted/20 ${vitalsWarnings.temperature ? "border-amber-500 bg-amber-50/10 focus-visible:ring-amber-500" : "border-border"}`}
                                            placeholder="36.5"
                                        />
                                        {vitalsWarnings.temperature && (
                                            <p className="text-[10px] text-amber-500 font-medium flex items-center gap-0.5">
                                                <AlertTriangle className="w-3 h-3 shrink-0" /> {vitalsWarnings.temperature}
                                            </p>
                                        )}
                                    </div>

                                    {/* Systolic */}
                                    <div className="space-y-1">
                                        <Label htmlFor="vitals_sys" className="text-xs font-semibold text-muted-foreground">Systolic BP (mmHg)</Label>
                                        <Input 
                                            id="vitals_sys"
                                            type="number"
                                            value={data.vitals.systolic_bp}
                                            onChange={e => handleVitalChange("systolic_bp", e.target.value)}
                                            className={`bg-muted/20 ${vitalsWarnings.systolic_bp ? "border-amber-500 bg-amber-50/10 focus-visible:ring-amber-500" : "border-border"}`}
                                            placeholder="120"
                                        />
                                        {vitalsWarnings.systolic_bp && (
                                            <p className="text-[10px] text-amber-500 font-medium flex items-center gap-0.5">
                                                <AlertTriangle className="w-3 h-3 shrink-0" /> {vitalsWarnings.systolic_bp}
                                            </p>
                                        )}
                                    </div>

                                    {/* Diastolic */}
                                    <div className="space-y-1">
                                        <Label htmlFor="vitals_dias" className="text-xs font-semibold text-muted-foreground">Diastolic BP (mmHg)</Label>
                                        <Input 
                                            id="vitals_dias"
                                            type="number"
                                            value={data.vitals.diastolic_bp}
                                            onChange={e => handleVitalChange("diastolic_bp", e.target.value)}
                                            className={`bg-muted/20 ${vitalsWarnings.diastolic_bp ? "border-amber-500 bg-amber-50/10 focus-visible:ring-amber-500" : "border-border"}`}
                                            placeholder="80"
                                        />
                                        {vitalsWarnings.diastolic_bp && (
                                            <p className="text-[10px] text-amber-500 font-medium flex items-center gap-0.5">
                                                <AlertTriangle className="w-3 h-3 shrink-0" /> {vitalsWarnings.diastolic_bp}
                                            </p>
                                        )}
                                    </div>

                                    {/* Pulse */}
                                    <div className="space-y-1">
                                        <Label htmlFor="vitals_pulse" className="text-xs font-semibold text-muted-foreground">Pulse Rate (bpm)</Label>
                                        <Input 
                                            id="vitals_pulse"
                                            type="number"
                                            value={data.vitals.pulse_rate}
                                            onChange={e => handleVitalChange("pulse_rate", e.target.value)}
                                            className={`bg-muted/20 ${vitalsWarnings.pulse_rate ? "border-amber-500 bg-amber-50/10 focus-visible:ring-amber-500" : "border-border"}`}
                                            placeholder="72"
                                        />
                                        {vitalsWarnings.pulse_rate && (
                                            <p className="text-[10px] text-amber-500 font-medium flex items-center gap-0.5">
                                                <AlertTriangle className="w-3 h-3 shrink-0" /> {vitalsWarnings.pulse_rate}
                                            </p>
                                        )}
                                    </div>

                                    {/* SpO2 */}
                                    <div className="space-y-1">
                                        <Label htmlFor="vitals_spo2" className="text-xs font-semibold text-muted-foreground">SpO2 (%)</Label>
                                        <Input 
                                            id="vitals_spo2"
                                            type="number"
                                            value={data.vitals.spo2}
                                            onChange={e => handleVitalChange("spo2", e.target.value)}
                                            className={`bg-muted/20 ${vitalsWarnings.spo2 ? "border-amber-500 bg-amber-50/10 focus-visible:ring-amber-500" : "border-border"}`}
                                            placeholder="98"
                                        />
                                        {vitalsWarnings.spo2 && (
                                            <p className="text-[10px] text-amber-500 font-medium flex items-center gap-0.5">
                                                <AlertTriangle className="w-3 h-3 shrink-0" /> {vitalsWarnings.spo2}
                                            </p>
                                        )}
                                    </div>

                                    {/* RR */}
                                    <div className="space-y-1">
                                        <Label htmlFor="vitals_rr" className="text-xs font-semibold text-muted-foreground">Resp Rate (bpm)</Label>
                                        <Input 
                                            id="vitals_rr"
                                            type="number"
                                            value={data.vitals.respiratory_rate}
                                            onChange={e => handleVitalChange("respiratory_rate", e.target.value)}
                                            className={`bg-muted/20 ${vitalsWarnings.respiratory_rate ? "border-amber-500 bg-amber-50/10 focus-visible:ring-amber-500" : "border-border"}`}
                                            placeholder="16"
                                        />
                                        {vitalsWarnings.respiratory_rate && (
                                            <p className="text-[10px] text-amber-500 font-medium flex items-center gap-0.5">
                                                <AlertTriangle className="w-3 h-3 shrink-0" /> {vitalsWarnings.respiratory_rate}
                                            </p>
                                        )}
                                    </div>

                                    {/* Weight */}
                                    <div className="space-y-1">
                                        <Label htmlFor="vitals_wt" className="text-xs font-semibold text-muted-foreground">Weight (kg)</Label>
                                        <Input 
                                            id="vitals_wt"
                                            type="number"
                                            value={data.vitals.weight}
                                            onChange={e => handleVitalChange("weight", e.target.value)}
                                            className={`bg-muted/20 ${vitalsWarnings.weight ? "border-amber-500 bg-amber-50/10 focus-visible:ring-amber-500" : "border-border"}`}
                                            placeholder="65"
                                        />
                                        {vitalsWarnings.weight && (
                                            <p className="text-[10px] text-amber-500 font-medium flex items-center gap-0.5">
                                                <AlertTriangle className="w-3 h-3 shrink-0" /> {vitalsWarnings.weight}
                                            </p>
                                        )}
                                    </div>

                                    {/* Height */}
                                    <div className="space-y-1">
                                        <Label htmlFor="vitals_ht" className="text-xs font-semibold text-muted-foreground">Height (cm)</Label>
                                        <Input 
                                            id="vitals_ht"
                                            type="number"
                                            value={data.vitals.height}
                                            onChange={e => handleVitalChange("height", e.target.value)}
                                            className={`bg-muted/20 ${vitalsWarnings.height ? "border-amber-500 bg-amber-50/10 focus-visible:ring-amber-500" : "border-border"}`}
                                            placeholder="170"
                                        />
                                        {vitalsWarnings.height && (
                                            <p className="text-[10px] text-amber-500 font-medium flex items-center gap-0.5">
                                                <AlertTriangle className="w-3 h-3 shrink-0" /> {vitalsWarnings.height}
                                            </p>
                                        )}
                                    </div>

                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="objective" className="text-sm font-bold text-foreground">
                                    Physical Exam & Objective Findings
                                </Label>
                                <Textarea 
                                    id="objective"
                                    value={data.objective}
                                    onChange={e => setData("objective", e.target.value)}
                                    rows={5}
                                    placeholder="Enter physical observations, state of consciousness, local status exams..."
                                    className="bg-muted/30 border-border text-foreground"
                                />
                                {errors.objective && <p className="text-xs text-destructive">{errors.objective}</p>}
                            </div>
                        </TabsContent>

                        {/* Assessment */}
                        <TabsContent value="assessment" className="p-6 space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="assessment" className="text-sm font-bold text-foreground">
                                    Assessment & General Progress Notes
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Record clinical deductions, working diagnoses, and general developmental assessment notes.
                                </p>
                                <Textarea 
                                    id="assessment"
                                    value={data.assessment}
                                    onChange={e => setData("assessment", e.target.value)}
                                    rows={8}
                                    placeholder="Enter diagnostic assessments, differential considerations, progress summaries..."
                                    className="bg-muted/30 border-border text-foreground"
                                />
                                {errors.assessment && <p className="text-xs text-destructive">{errors.assessment}</p>}
                            </div>
                        </TabsContent>

                        {/* Plan */}
                        <TabsContent value="plan" className="p-6 space-y-6">
                            <div className="space-y-2">
                                <Label htmlFor="plan" className="text-sm font-bold text-foreground">
                                    Therapeutic Plan
                                </Label>
                                <Textarea 
                                    id="plan"
                                    value={data.plan}
                                    onChange={e => setData("plan", e.target.value)}
                                    rows={5}
                                    placeholder="Enter education, follow-ups, lifestyle instructions, or therapy details..."
                                    className="bg-muted/30 border-border text-foreground"
                                />
                                {errors.plan && <p className="text-xs text-destructive">{errors.plan}</p>}
                            </div>

                            <div className="border-t pt-6 space-y-4">
                                <h3 className="text-sm font-bold text-foreground flex items-center gap-2">
                                    Structured Electronic Prescriptions
                                </h3>

                                {isEdit ? (
                                    <div className="space-y-4">
                                        <PrescriptionPanel 
                                            mode="prescribe"
                                            registrationId={registration.id}
                                            soapNoteId={note.id}
                                            prescriptions={note.prescriptions || []}
                                        />
                                    </div>
                                ) : (
                                    <div className="bg-amber-500/5 rounded-xl border border-amber-500/20 p-4 flex gap-3 text-amber-500">
                                        <AlertTriangle className="w-5 h-5 shrink-0" />
                                        <div className="text-xs space-y-1">
                                            <p className="font-bold">Bedside E-Prescription requires a Saved Draft</p>
                                            <p className="text-muted-foreground">To add structured medications for this visit, please save your SOAP note as a draft first. E-prescriptions will link automatically.</p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </TabsContent>

                    </Tabs>
                </div>

                {/* Submissions Action Bar */}
                <div className="flex justify-between items-center gap-4 bg-muted/40 p-4 rounded-xl border shadow-sm">
                    <div className="text-xs text-muted-foreground flex items-center gap-2">
                        <Lock className="w-4 h-4 text-primary" />
                        <span>EMR Audit Compliant Active</span>
                    </div>

                    <div className="flex items-center gap-3">
                        <ShadButton 
                            type="submit" 
                            disabled={processing}
                            className="font-bold gap-2"
                        >
                            {processing ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
                            {isEdit ? "Save Draft" : "Create Draft"}
                        </ShadButton>

                        {isEdit && (
                            <ShadButton 
                                type="button" 
                                variant="default"
                                onClick={() => setIsSubmitDialogOpen(true)}
                                className="bg-emerald-600 hover:bg-emerald-700 text-white font-bold gap-2"
                            >
                                <CheckCircle className="w-4 h-4" /> Finalize & Submit
                            </ShadButton>
                        )}
                    </div>
                </div>

            </form>

            {/* Right Column: Diagnostic Scanned Documents sidebar panel (4 cols) */}
            <div className="lg:col-span-4 space-y-6">
                
                <div className="bg-card rounded-2xl border border-border p-5 shadow-sm space-y-4">
                    <h3 className="text-sm font-bold text-foreground flex items-center gap-2 uppercase tracking-wider text-[11px] text-muted-foreground">
                        Diagnostic Quick-Reference Panel
                    </h3>
                    
                    {patientDocuments.length === 0 ? (
                        <div className="text-center py-10 text-xs text-muted-foreground border border-dashed rounded-xl p-4">
                            No scanned diagnostic lab or radiology results associated with this encounter registration.
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-2.5">
                            {patientDocuments.map((doc) => (
                                <div 
                                    key={doc.id}
                                    className="bg-muted/40 hover:bg-muted border rounded-xl p-3 flex justify-between items-center gap-4 transition-all"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="p-2 bg-primary/10 rounded-lg">
                                            <FileImage className="w-4 h-4 text-primary" />
                                        </div>
                                        <div className="text-left">
                                            <p className="text-xs font-bold text-foreground truncate max-w-[150px]">{doc.document_name}</p>
                                            <span className="text-[10px] text-muted-foreground font-mono uppercase bg-muted/60 px-1.5 py-0.5 rounded border border-border mt-1 inline-block">
                                                {doc.document_type}
                                            </span>
                                        </div>
                                    </div>

                                    <ShadButton 
                                        type="button" 
                                        variant="ghost" 
                                        size="icon"
                                        onClick={() => setPreviewDoc(doc)}
                                        className="h-8 w-8 text-primary"
                                    >
                                        <Maximize2 className="w-3.5 h-3.5" />
                                    </ShadButton>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

            </div>

            {/* Document Preview Modal */}
            {previewDoc && (
                <Dialog open={true} onOpenChange={() => setPreviewDoc(null)}>
                    <DialogContent className="max-w-4xl bg-slate-900 border-slate-800 text-white">
                        <DialogHeader>
                            <DialogTitle className="text-lg font-bold flex justify-between items-center pr-6 text-white">
                                {previewDoc.document_name}
                            </DialogTitle>
                            <DialogDescription className="text-slate-400">
                                Scanned {previewDoc.document_type} finding associated with the active registration.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="my-4 bg-black border border-slate-850 rounded-xl overflow-hidden min-h-[400px] flex items-center justify-center p-2">
                            {previewDoc.file_path.endsWith(".pdf") ? (
                                <iframe 
                                    src={`/storage/${previewDoc.file_path}`} 
                                    className="w-full h-[500px] border-none rounded"
                                    title="PDF Document Preview"
                                />
                            ) : (
                                <img 
                                    src={`/storage/${previewDoc.file_path}`} 
                                    alt="Clinical Scan Preview" 
                                    className="max-h-[500px] object-contain rounded"
                                />
                            )}
                        </div>

                        <DialogFooter>
                            <ShadButton 
                                variant="outline" 
                                onClick={() => setPreviewDoc(null)}
                                className="border-slate-800 bg-slate-950 text-slate-300 hover:text-white"
                            >
                                Close
                            </ShadButton>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}

            {/* Diagnoses Finalization Dialog */}
            {isSubmitDialogOpen && (
                <Dialog open={true} onOpenChange={() => setIsSubmitDialogOpen(false)}>
                    <DialogContent className="max-w-3xl bg-card border-border text-foreground">
                        <DialogHeader>
                            <DialogTitle className="text-lg font-bold text-foreground">
                                Finalize EMR SOAP Note - Diagnoses Link
                            </DialogTitle>
                            <DialogDescription className="text-muted-foreground text-xs mt-1">
                                Submitting will finalize and LOCK this note. Every diagnosed pathology must be resolved here. These diagnoses will propagate directly to HospitalCore's active registration record.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4 my-4">
                            
                            {/* ICD Search Bar */}
                            <div className="relative">
                                <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input 
                                    placeholder="Search ICD-10 codes by code or name..."
                                    value={icdQuery}
                                    onChange={e => setIcdQuery(e.target.value)}
                                    className="pl-9 bg-muted/40 border-border"
                                />
                                
                                {isSearchingIcd && (
                                    <div className="absolute right-3 top-2.5">
                                        <Loader2 className="w-4 h-4 animate-spin text-primary" />
                                    </div>
                                )}

                                {icdResults.length > 0 && (
                                    <div className="absolute z-50 w-full bg-card border border-border rounded-xl shadow-lg mt-1 max-h-[220px] overflow-y-auto divide-y divide-border">
                                        {icdResults.map((icd) => (
                                            <button
                                                key={icd.id}
                                                type="button"
                                                onClick={() => addDiagnosis(icd)}
                                                className="w-full text-left px-4 py-2.5 hover:bg-muted text-xs font-medium transition-all flex items-center justify-between"
                                            >
                                                <span><strong className="text-primary font-mono text-xs">{icd.code}</strong> - {icd.description}</span>
                                                <Plus className="w-3.5 h-3.5 text-primary" />
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Linked Diagnoses List */}
                            <div className="border border-border rounded-xl overflow-hidden bg-card">
                                <Table>
                                    <TableHeader className="bg-muted/50">
                                        <TableRow>
                                            <TableHead className="w-24 text-xs font-bold">ICD-10 Code</TableHead>
                                            <TableHead className="text-xs font-bold">Description</TableHead>
                                            <TableHead className="w-36 text-xs font-bold">Classification</TableHead>
                                            <TableHead className="text-xs font-bold">Physician Notes</TableHead>
                                            <TableHead className="w-12" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {diagnoses.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center py-8 text-xs text-muted-foreground">
                                                    No ICD-10 diagnoses added. Search and add above.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            diagnoses.map((diag, idx) => (
                                                <TableRow key={diag.icd_code_id}>
                                                    <TableCell className="font-mono text-xs font-bold text-primary">{diag.code}</TableCell>
                                                    <TableCell className="text-xs max-w-[200px] truncate">{diag.description}</TableCell>
                                                    <TableCell>
                                                        <select
                                                            value={diag.diagnosis_type}
                                                            onChange={e => updateDiagnosisType(idx, e.target.value as any)}
                                                            className="w-full h-8 bg-muted border border-border rounded-md px-2 text-xs text-foreground focus:outline-none focus:border-primary"
                                                        >
                                                            <option value="primary">Primary</option>
                                                            <option value="secondary">Secondary</option>
                                                        </select>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Input 
                                                            value={diag.notes}
                                                            onChange={e => updateDiagnosisNotes(idx, e.target.value)}
                                                            className="h-8 bg-muted/40 text-xs border-border"
                                                            placeholder="Add specific notes..."
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <ShadButton 
                                                            type="button" 
                                                            variant="ghost" 
                                                            size="icon"
                                                            onClick={() => removeDiagnosis(idx)}
                                                            className="h-7 w-7 text-destructive hover:bg-destructive/10"
                                                        >
                                                            <Trash2 className="w-3.5 h-3.5" />
                                                        </ShadButton>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>

                        </div>

                        <DialogFooter className="gap-2">
                            <ShadButton 
                                variant="outline" 
                                onClick={() => setIsSubmitDialogOpen(false)}
                                className="border-border text-foreground"
                            >
                                Cancel
                            </ShadButton>
                            
                            <ShadButton 
                                onClick={handleConfirmFinalize}
                                disabled={submittingNote || diagnoses.length === 0}
                                className="bg-emerald-600 hover:bg-emerald-700 text-white font-bold gap-2"
                            >
                                {submittingNote ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle className="w-4 h-4" />}
                                Complete & Lock Note
                            </ShadButton>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}

        </div>
    );
}
