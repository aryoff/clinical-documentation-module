import { ReactNode, useState } from "react";
import { Head, Link } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout/AuthenticatedLayout";
import Content from "@/Layouts/AuthenticatedLayout/Components/Content";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { 
    Search, 
    FileText, 
    Calendar, 
    Stethoscope, 
    ChevronRight,
    Activity
} from "lucide-react";
import { format } from "date-fns";

interface Registration {
    id: string;
    type: string;
    registration_number: string;
    registered_at: string;
    patient: {
        id: string;
        full_name: string;
        nocm: string;
        gender: string;
        dob: string;
    };
    doctor?: {
        name: string;
    } | null;
    department?: {
        name: string;
    } | null;
}

interface Props {
    registrations: {
        data: Registration[];
        links: any[];
        current_page: number;
        last_page: number;
    };
}

export default function Index({ registrations }: Props) {
    const [searchQuery, setSearchQuery] = useState("");
    const [activeTab, setActiveTab] = useState<"all" | "outpatient" | "inpatient" | "emergency">("all");

    const filteredData = registrations.data.filter(reg => {
        const matchesSearch = 
            reg.patient.full_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
            reg.patient.nocm.toLowerCase().includes(searchQuery.toLowerCase()) ||
            reg.registration_number.toLowerCase().includes(searchQuery.toLowerCase());
        
        const matchesTab = activeTab === "all" || reg.type === activeTab;

        return matchesSearch && matchesTab;
    });

    const getGenderBadge = (gender: string) => {
        return gender === "L" || gender === "male" || gender === "Laki-laki"
            ? <Badge variant="outline" className="text-blue-500 border-blue-200 bg-blue-50/50">M</Badge>
            : <Badge variant="outline" className="text-pink-500 border-pink-200 bg-pink-50/50">F</Badge>;
    };

    const getRegistrationTypeBadge = (type: string) => {
        switch (type) {
            case "outpatient":
                return <Badge variant="default" className="bg-emerald-500 hover:bg-emerald-600 font-bold uppercase tracking-wider text-[10px]">Outpatient</Badge>;
            case "inpatient":
                return <Badge variant="secondary" className="bg-indigo-500 hover:bg-indigo-600 text-white font-bold uppercase tracking-wider text-[10px]">Inpatient</Badge>;
            case "emergency":
                return <Badge variant="destructive" className="bg-rose-500 hover:bg-rose-600 font-bold uppercase tracking-wider text-[10px]">Emergency</Badge>;
            default:
                return <Badge variant="outline">{type}</Badge>;
        }
    };

    return (
        <Content>
            <Head title="Clinical Documentation - Registrations" />
            <div className="max-w-7xl mx-auto space-y-6 pb-12">
                {/* Hero Header */}
                <div className="bg-primary/5 rounded-2xl border border-primary/10 p-6 md:p-8 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-sm">
                    <div className="space-y-2">
                        <h1 className="text-3xl font-black tracking-tight text-foreground flex items-center gap-2">
                            <Activity className="w-8 h-8 text-primary" /> EMR Dashboard
                        </h1>
                        <p className="text-muted-foreground text-sm max-w-xl">
                            Select an active clinical encounter below to record vital signs, document SOAP notes, request diagnostic exams, and prescribe medications.
                        </p>
                    </div>
                </div>

                {/* Filters & Search */}
                <div className="flex flex-col md:flex-row gap-4 justify-between items-center bg-card p-4 rounded-xl border shadow-sm">
                    <div className="flex flex-wrap gap-2 w-full md:w-auto">
                        <Button 
                            variant={activeTab === "all" ? "default" : "ghost"}
                            onClick={() => setActiveTab("all")}
                            className="font-bold text-xs uppercase"
                        >
                            All Encounters
                        </Button>
                        <Button 
                            variant={activeTab === "outpatient" ? "default" : "ghost"}
                            onClick={() => setActiveTab("outpatient")}
                            className="font-bold text-xs uppercase"
                        >
                            Outpatient
                        </Button>
                        <Button 
                            variant={activeTab === "emergency" ? "default" : "ghost"}
                            onClick={() => setActiveTab("emergency")}
                            className="font-bold text-xs uppercase"
                        >
                            Emergency
                        </Button>
                        <Button 
                            variant={activeTab === "inpatient" ? "default" : "ghost"}
                            onClick={() => setActiveTab("inpatient")}
                            className="font-bold text-xs uppercase"
                        >
                            Inpatient
                        </Button>
                    </div>

                    <div className="relative w-full md:w-80">
                        <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input 
                            placeholder="Search by Patient Name or MR..." 
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="pl-9 bg-muted/40 border-border"
                        />
                    </div>
                </div>

                {/* Patients List Grid */}
                <div className="grid grid-cols-1 gap-4">
                    {filteredData.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-20 text-muted-foreground bg-muted/15 rounded-2xl border border-dashed border-border p-8 text-center">
                            <FileText className="w-12 h-12 mb-4 text-muted-foreground/30" />
                            <h3 className="text-lg font-bold text-foreground">No active encounters found</h3>
                            <p className="text-sm max-w-sm mt-1">There are no patient registrations matching your current filter. Please register a patient first.</p>
                        </div>
                    ) : (
                        filteredData.map((reg) => (
                            <div 
                                key={reg.id}
                                className="bg-card rounded-2xl border border-border p-5 shadow-sm hover:shadow-md transition-all hover:border-primary/20 flex flex-col md:flex-row justify-between items-start md:items-center gap-6"
                            >
                                <div className="flex-1 space-y-3">
                                    <div className="flex flex-wrap items-center gap-3">
                                        <Badge variant="outline" className="font-mono text-xs border-primary/20 text-primary bg-primary/5">
                                            MR: {reg.patient.nocm}
                                        </Badge>
                                        {getRegistrationTypeBadge(reg.type)}
                                        <div className="text-[11px] text-muted-foreground font-medium flex items-center gap-1">
                                            <Calendar className="w-3.5 h-3.5" />
                                            {format(new Date(reg.registered_at), "PPP p")}
                                        </div>
                                    </div>

                                    <div>
                                        <h2 className="text-xl font-bold text-foreground flex items-center gap-2 tracking-tight">
                                            {reg.patient.full_name}
                                            {getGenderBadge(reg.patient.gender)}
                                        </h2>
                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground mt-1">
                                            <div className="flex items-center gap-1">
                                                <Stethoscope className="w-4 h-4 text-primary/70" />
                                                <span>Doctor: <strong className="text-foreground font-semibold">{reg.doctor?.name ?? 'Unassigned'}</strong></span>
                                            </div>
                                            <span className="hidden md:inline">•</span>
                                            <div>
                                                <span>Dept: <strong className="text-foreground font-semibold">{reg.department?.name ?? 'N/A'}</strong></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center gap-3 w-full md:w-auto shrink-0 border-t pt-4 md:border-t-0 md:pt-0">
                                    <Link 
                                        href={route("clinicaldocumentation.create", { registration_id: reg.id })}
                                        className="w-full md:w-auto"
                                    >
                                        <Button className="w-full font-bold gap-2">
                                            <FileText className="w-4 h-4" /> Open EMR Note <ChevronRight className="w-4 h-4" />
                                        </Button>
                                    </Link>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </Content>
    );
}

Index.layout = (page: ReactNode) => (
    <AuthenticatedLayout children={page} header="Clinical EMR Documentation" />
);
