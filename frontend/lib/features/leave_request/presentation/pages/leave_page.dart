import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:file_picker/file_picker.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/widgets/app_loading.dart';
import '../../../../core/network/api_client.dart';
import '../../../../core/network/network_info.dart';
import '../bloc/leave_bloc.dart';
import '../bloc/leave_event.dart';
import '../bloc/leave_state.dart';
import '../../domain/entities/leave_entities.dart';
import '../../data/datasources/leave_remote_datasource.dart';
import '../../data/repositories/leave_repository_impl.dart';

class LeavePage extends StatelessWidget {
  const LeavePage({super.key});

  @override
  Widget build(BuildContext context) {
    final apiClient = context.read<ApiClient>();
    final networkInfo = context.read<NetworkInfo>();
    final remoteDataSource = LeaveRemoteDataSourceImpl(apiClient);
    final repository = LeaveRepositoryImpl(remoteDataSource, networkInfo);

    return BlocProvider(
      create: (_) => LeaveBloc(repository: repository)..add(LoadMyLeaves()),
      child: const _LeaveView(),
    );
  }
}

class _LeaveView extends StatelessWidget {
  const _LeaveView();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Pengajuan Izin')),
      body: BlocConsumer<LeaveBloc, LeaveState>(
        listener: (context, state) {
          if (state is LeaveSubmitted) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text('Pengajuan berhasil dikirim'),
                backgroundColor: AppColors.success,
              ),
            );
            context.read<LeaveBloc>().add(LoadMyLeaves());
          } else if (state is LeaveError) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text(state.message),
                backgroundColor: AppColors.danger,
              ),
            );
          }
        },
        builder: (context, state) {
          if (state is LeaveLoading) {
            return const AppLoading(message: 'Memuat data...');
          }
          if (state is LeaveError && state is! LeaveLoaded) {
            return AppErrorWidget(
              message: state.message,
              onRetry: () => context.read<LeaveBloc>().add(LoadMyLeaves()),
            );
          }
          if (state is LeaveLoaded) {
            if (state.leaves.isEmpty) {
              return const AppEmptyState(
                title: 'Belum ada pengajuan',
                subtitle: 'Tekan tombol + untuk mengajukan izin atau sakit',
                icon: Icons.description_outlined,
              );
            }
            return RefreshIndicator(
              onRefresh: () async {
                context.read<LeaveBloc>().add(LoadMyLeaves());
              },
              child: ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: state.leaves.length,
                itemBuilder: (context, index) {
                  return _LeaveCard(leave: state.leaves[index]);
                },
              ),
            );
          }
          return const SizedBox.shrink();
        },
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () => _showSubmitForm(context),
        backgroundColor: AppColors.primary,
        child: const Icon(Icons.add, color: Colors.white),
      ),
    );
  }

  void _showSubmitForm(BuildContext context) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (_) => BlocProvider.value(
        value: context.read<LeaveBloc>(),
        child: const _SubmitLeaveSheet(),
      ),
    );
  }
}

class _LeaveCard extends StatelessWidget {
  final LeaveRequest leave;

  const _LeaveCard({required this.leave});

  @override
  Widget build(BuildContext context) {
    final statusColor = _getStatusColor(leave.status);
    final jenisColor = leave.jenis == 'sakit'
        ? AppColors.sakit
        : AppColors.info;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: jenisColor.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  leave.jenis == 'sakit' ? 'Sakit' : 'Izin',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: jenisColor,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  _getStatusLabel(leave.status),
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: statusColor,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          if (leave.mataKuliahName != null)
            Text(
              leave.mataKuliahName!,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
            ),
          const SizedBox(height: 4),
          Text(
            '${leave.tanggalMulai} - ${leave.tanggalSelesai}',
            style: const TextStyle(fontSize: 12, color: AppColors.textMuted),
          ),
          const SizedBox(height: 4),
          Text(
            leave.keterangan,
            style: const TextStyle(
              fontSize: 12,
              color: AppColors.textSecondary,
            ),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
          if (leave.rejectedReason != null && leave.rejectedReason!.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'Ditolak: ${leave.rejectedReason}',
                style: const TextStyle(fontSize: 12, color: AppColors.danger),
              ),
            ),
        ],
      ),
    );
  }

  Color _getStatusColor(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
        return AppColors.success;
      case 'pending':
        return AppColors.warning;
      case 'rejected':
        return AppColors.danger;
      default:
        return AppColors.textMuted;
    }
  }

  String _getStatusLabel(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
        return 'Disetujui';
      case 'pending':
        return 'Menunggu';
      case 'rejected':
        return 'Ditolak';
      default:
        return status;
    }
  }
}

class _SubmitLeaveSheet extends StatefulWidget {
  const _SubmitLeaveSheet();

  @override
  State<_SubmitLeaveSheet> createState() => _SubmitLeaveSheetState();
}

class _SubmitLeaveSheetState extends State<_SubmitLeaveSheet> {
  final _formKey = GlobalKey<FormState>();
  final _keteranganController = TextEditingController();

  String _jenis = 'izin';
  DateTime? _tanggalMulai;
  DateTime? _tanggalSelesai;
  String? _fileName;
  String? _filePath;

  @override
  void dispose() {
    _keteranganController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 16,
        right: 16,
        top: 16,
        bottom: MediaQuery.of(context).viewInsets.bottom + 16,
      ),
      child: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.border,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              const Text(
                'Ajukan Izin / Sakit',
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              const SizedBox(height: 16),
              DropdownButtonFormField<String>(
                initialValue: _jenis,
                decoration: const InputDecoration(
                  labelText: 'Jenis',
                  border: OutlineInputBorder(),
                ),
                items: const [
                  DropdownMenuItem(value: 'izin', child: Text('Izin')),
                  DropdownMenuItem(value: 'sakit', child: Text('Sakit')),
                ],
                onChanged: (value) {
                  if (value != null) setState(() => _jenis = value);
                },
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => _pickDate(isStart: true),
                      icon: const Icon(Icons.calendar_today, size: 16),
                      label: Text(
                        _tanggalMulai != null
                            ? '${_tanggalMulai!.day}/${_tanggalMulai!.month}/${_tanggalMulai!.year}'
                            : 'Tanggal Mulai',
                        style: TextStyle(
                          color: _tanggalMulai != null
                              ? AppColors.textPrimary
                              : AppColors.textMuted,
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => _pickDate(isStart: false),
                      icon: const Icon(Icons.calendar_today, size: 16),
                      label: Text(
                        _tanggalSelesai != null
                            ? '${_tanggalSelesai!.day}/${_tanggalSelesai!.month}/${_tanggalSelesai!.year}'
                            : 'Tanggal Selesai',
                        style: TextStyle(
                          color: _tanggalSelesai != null
                              ? AppColors.textPrimary
                              : AppColors.textMuted,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _keteranganController,
                decoration: const InputDecoration(
                  labelText: 'Keterangan',
                  border: OutlineInputBorder(),
                  alignLabelWithHint: true,
                ),
                maxLines: 3,
                validator: (value) {
                  if (value == null || value.trim().isEmpty) {
                    return 'Keterangan wajib diisi';
                  }
                  return null;
                },
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: _pickFile,
                icon: const Icon(Icons.attach_file, size: 16),
                label: Text(
                  _fileName ?? 'Upload Surat (opsional)',
                  style: TextStyle(
                    color: _fileName != null
                        ? AppColors.textPrimary
                        : AppColors.textMuted,
                  ),
                ),
              ),
              const SizedBox(height: 20),
              BlocBuilder<LeaveBloc, LeaveState>(
                builder: (context, state) {
                  final isLoading = state is LeaveLoading;
                  return SizedBox(
                    width: double.infinity,
                    height: 48,
                    child: ElevatedButton(
                      onPressed: isLoading ? null : _submit,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.primary,
                        foregroundColor: Colors.white,
                      ),
                      child: isLoading
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                  Colors.white,
                                ),
                              ),
                            )
                          : const Text('Kirim Pengajuan'),
                    ),
                  );
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _pickDate({required bool isStart}) async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: now,
      firstDate: now.subtract(const Duration(days: 30)),
      lastDate: now.add(const Duration(days: 365)),
    );
    if (picked != null) {
      setState(() {
        if (isStart) {
          _tanggalMulai = picked;
          if (_tanggalSelesai != null && _tanggalSelesai!.isBefore(picked)) {
            _tanggalSelesai = picked;
          }
        } else {
          _tanggalSelesai = picked;
        }
      });
    }
  }

  Future<void> _pickFile() async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png'],
    );
    if (result != null && result.files.isNotEmpty) {
      setState(() {
        _fileName = result.files.single.name;
        _filePath = result.files.single.path;
      });
    }
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    if (_tanggalMulai == null || _tanggalSelesai == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Pilih tanggal mulai dan selesai'),
          backgroundColor: AppColors.warning,
        ),
      );
      return;
    }

    final mulai =
        '${_tanggalMulai!.year}-${_tanggalMulai!.month.toString().padLeft(2, '0')}-${_tanggalMulai!.day.toString().padLeft(2, '0')}';
    final selesai =
        '${_tanggalSelesai!.year}-${_tanggalSelesai!.month.toString().padLeft(2, '0')}-${_tanggalSelesai!.day.toString().padLeft(2, '0')}';

    context.read<LeaveBloc>().add(
      SubmitLeave(
        jenis: _jenis,
        tanggalMulai: mulai,
        tanggalSelesai: selesai,
        keterangan: _keteranganController.text.trim(),
        filePath: _filePath,
      ),
    );

    Navigator.of(context).pop();
  }
}
